import type { BankAccountEvidence, BankAccountEvidenceKind } from "./BankAccountEvidence";

/** The container key the {@link BankAccountEvidenceRepository} port is bound under. */
export const BANK_ACCOUNT_EVIDENCE_REPOSITORY_KEY = "BackOfficeBankAccountEvidenceRepository";

/**
 * The documents a bank account holds as evidence. The attach names a document an earlier upload
 * filed, by id; the bytes never travel through this port.
 */
export interface BankAccountEvidenceRepository {
  list(bankAccountId: string): Promise<BankAccountEvidence[]>;
  attach(
    bankAccountId: string,
    documentId: string,
    kind: BankAccountEvidenceKind,
  ): Promise<BankAccountEvidence>;
}
