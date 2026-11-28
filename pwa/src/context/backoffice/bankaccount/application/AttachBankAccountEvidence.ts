import { inject, injectable } from "inversify";
import {
  DOCUMENT_UPLOADER_KEY,
  type DocumentUploader,
  type UploadOptions,
} from "@/context/backoffice/document/domain/DocumentUploader";
import {
  EVIDENCE_RETENTION,
  type BankAccountEvidence,
  type BankAccountEvidenceKind,
} from "../domain/BankAccountEvidence";
import {
  BANK_ACCOUNT_EVIDENCE_REPOSITORY_KEY,
  type BankAccountEvidenceRepository,
} from "../domain/BankAccountEvidenceRepository";

export interface AttachOptions extends UploadOptions {
  /** Called once the original is filed, before the attach is sent: from here the upload cannot be cancelled. */
  readonly onFiled?: () => void;
}

/**
 * Files an original through the Documents upload under its kind's retention terms, then attaches
 * the document it filed to the bank account. Two server writes, in that order: a refused attach
 * leaves a filed document attached to nothing, which the next attempt files again rather than
 * reuses — the upload carries no idempotency key, because one needs the digest of the whole file.
 */
@injectable()
export class AttachBankAccountEvidence {
  constructor(
    @inject(DOCUMENT_UPLOADER_KEY) private readonly uploader: DocumentUploader,
    @inject(BANK_ACCOUNT_EVIDENCE_REPOSITORY_KEY)
    private readonly repository: BankAccountEvidenceRepository,
  ) {}

  async run(
    bankAccountId: string,
    file: File,
    kind: BankAccountEvidenceKind,
    options?: AttachOptions,
  ): Promise<BankAccountEvidence> {
    const filed = await this.uploader.upload(file, EVIDENCE_RETENTION[kind], {
      onProgress: options?.onProgress,
      signal: options?.signal,
    });
    options?.onFiled?.();
    return this.repository.attach(bankAccountId, filed.id, kind);
  }
}
