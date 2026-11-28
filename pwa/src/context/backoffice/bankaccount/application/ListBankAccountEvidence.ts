import { inject, injectable } from "inversify";
import type { BankAccountEvidence } from "../domain/BankAccountEvidence";
import {
  BANK_ACCOUNT_EVIDENCE_REPOSITORY_KEY,
  type BankAccountEvidenceRepository,
} from "../domain/BankAccountEvidenceRepository";

@injectable()
export class ListBankAccountEvidence {
  constructor(
    @inject(BANK_ACCOUNT_EVIDENCE_REPOSITORY_KEY)
    private readonly repository: BankAccountEvidenceRepository,
  ) {}

  async run(bankAccountId: string): Promise<BankAccountEvidence[]> {
    return this.repository.list(bankAccountId);
  }
}
