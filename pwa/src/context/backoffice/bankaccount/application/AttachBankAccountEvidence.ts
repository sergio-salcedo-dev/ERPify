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
    options?: UploadOptions,
  ): Promise<BankAccountEvidence> {
    const filed = await this.uploader.upload(file, EVIDENCE_RETENTION[kind], options);
    return this.repository.attach(bankAccountId, filed.id, kind);
  }
}
