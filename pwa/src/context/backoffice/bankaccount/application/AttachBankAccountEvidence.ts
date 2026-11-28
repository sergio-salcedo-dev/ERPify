import { inject, injectable } from "inversify";
import {
  DOCUMENT_UPLOADER_KEY,
  type DocumentUploader,
  type DocumentUploadProgress,
} from "@/context/shared/documents/domain/DocumentUploader";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import {
  EVIDENCE_RETENTION,
  type BankAccountEvidence,
  type BankAccountEvidenceRepository,
  type EvidenceKind,
} from "../domain/BankAccountEvidence";
import { BankAccountProblemType } from "../domain/BankAccountProblemType";
import { EvidenceAttachStage } from "../domain/evidenceProblemMessage";

export { EvidenceAttachStage } from "../domain/evidenceProblemMessage";

/** What the filing is doing now, for a caller that shows it. */
export const EvidenceProgressStage = {
  /** Declaring, then sending the body. */
  UPLOAD: "upload",
  /** The last byte has left; the server is still filing the original. */
  FINISHING: "finishing",
  /** The original is filed; attaching it to the account. */
  ATTACH: "attach",
} as const;

export type EvidenceProgressStage =
  (typeof EvidenceProgressStage)[keyof typeof EvidenceProgressStage];

export interface EvidenceAttachObserver {
  readonly onStage?: (stage: EvidenceProgressStage) => void;
  readonly onProgress?: (progress: DocumentUploadProgress) => void;
}

/**
 * A failure of {@link AttachBankAccountEvidence}. At the upload stage `contentSent` says whether the
 * body had started (before it, nothing can have been filed); at the attach stage `documentId` is the
 * document already filed, so the attach can be repeated without a second upload.
 */
export class EvidenceAttachFailure extends Error {
  constructor(
    readonly stage: EvidenceAttachStage,
    override readonly cause: unknown,
    readonly contentSent: boolean,
    readonly documentId: string | null,
  ) {
    super(`Evidence ${stage} failed`);
    this.name = "EvidenceAttachFailure";
  }
}

/**
 * Files an original as a bank account's evidence: uploads it under the retention terms its kind
 * fixes (never chosen by the caller), then attaches the filed document to the account. A failure is
 * rethrown as an {@link EvidenceAttachFailure} naming the step, because the remedy differs — an
 * upload that failed before its body started kept nothing, an attach that failed left a filed
 * document behind, which {@link retryAttach} attaches without uploading it again.
 */
@injectable()
export class AttachBankAccountEvidence {
  constructor(
    @inject(DOCUMENT_UPLOADER_KEY)
    private readonly uploader: DocumentUploader,
    @inject("BackOfficeBankAccountEvidenceRepository")
    private readonly repository: BankAccountEvidenceRepository,
  ) {}

  async run(
    accountId: string,
    file: File,
    kind: EvidenceKind,
    observer: EvidenceAttachObserver = {},
  ): Promise<BankAccountEvidence> {
    let contentSent = false;
    let documentId: string;
    observer.onStage?.(EvidenceProgressStage.UPLOAD);
    try {
      const filed = await this.uploader.upload(file, EVIDENCE_RETENTION[kind], {
        onSending: () => {
          contentSent = true;
        },
        onProgress: (progress) => observer.onProgress?.(progress),
        onSent: () => observer.onStage?.(EvidenceProgressStage.FINISHING),
      });
      documentId = filed.id;
    } catch (error) {
      throw new EvidenceAttachFailure(EvidenceAttachStage.UPLOAD, error, contentSent, null);
    }
    observer.onStage?.(EvidenceProgressStage.ATTACH);
    try {
      return await this.repository.attach(accountId, documentId, kind);
    } catch (error) {
      throw new EvidenceAttachFailure(EvidenceAttachStage.ATTACH, error, true, documentId);
    }
  }

  /**
   * Attaches a document an earlier {@link run} filed but did not attach. A 409
   * `bank-account-evidence-already-attached` means the earlier attach did commit before its answer
   * was lost, so it is the success it looks like a refusal of, and answers `null`.
   */
  async retryAttach(
    accountId: string,
    documentId: string,
    kind: EvidenceKind,
  ): Promise<BankAccountEvidence | null> {
    try {
      return await this.repository.attach(accountId, documentId, kind);
    } catch (error) {
      if (
        error instanceof HttpError &&
        error.problem.type === BankAccountProblemType.EVIDENCE_ALREADY_ATTACHED
      ) {
        return null;
      }
      throw new EvidenceAttachFailure(EvidenceAttachStage.ATTACH, error, true, documentId);
    }
  }
}
