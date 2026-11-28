import { inject, injectable } from "inversify";
import {
  DOCUMENT_UPLOADER_KEY,
  type DocumentUploader,
  type DocumentUploadProgress,
} from "@/context/shared/documents/domain/DocumentUploader";
import {
  EVIDENCE_RETENTION,
  type BankAccountEvidence,
  type BankAccountEvidenceRepository,
  type EvidenceKind,
} from "../domain/BankAccountEvidence";

/** Which of the two steps a failure came from: the original may already be kept after an `attach` one. */
export const EvidenceAttachStage = {
  UPLOAD: "upload",
  ATTACH: "attach",
} as const;

export type EvidenceAttachStage = (typeof EvidenceAttachStage)[keyof typeof EvidenceAttachStage];

/** A failure of {@link AttachBankAccountEvidence}, tagged with the step it came from. */
export class EvidenceAttachFailure extends Error {
  constructor(
    readonly stage: EvidenceAttachStage,
    override readonly cause: unknown,
  ) {
    super(`Evidence ${stage} failed`);
    this.name = "EvidenceAttachFailure";
  }
}

/**
 * Files an original as a bank account's evidence: uploads it under the retention terms its kind
 * fixes (never chosen by the caller), then attaches the filed document to the account. A failure is
 * rethrown as an {@link EvidenceAttachFailure} naming the step, because the remedy differs — an
 * upload that failed kept nothing, an attach that failed left a filed document behind.
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
    onProgress?: (progress: DocumentUploadProgress) => void,
  ): Promise<BankAccountEvidence> {
    let documentId: string;
    try {
      documentId = (await this.uploader.upload(file, EVIDENCE_RETENTION[kind], onProgress)).id;
    } catch (error) {
      throw new EvidenceAttachFailure(EvidenceAttachStage.UPLOAD, error);
    }
    try {
      return await this.repository.attach(accountId, documentId, kind);
    } catch (error) {
      throw new EvidenceAttachFailure(EvidenceAttachStage.ATTACH, error);
    }
  }
}
