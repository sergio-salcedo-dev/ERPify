import { inject, injectable } from "inversify";
import {
  BANK_ACCOUNT_EVIDENCE_REPOSITORY_KEY,
  type BankAccountEvidenceRepository,
} from "../domain/BankAccountEvidenceRepository";

/** Removes one link; the document stays filed and can be attached to the account it belongs to. */
@injectable()
export class DetachBankAccountEvidence {
  constructor(
    @inject(BANK_ACCOUNT_EVIDENCE_REPOSITORY_KEY)
    private readonly repository: BankAccountEvidenceRepository,
  ) {}

  async run(bankAccountId: string, evidenceId: string): Promise<void> {
    return this.repository.detach(bankAccountId, evidenceId);
  }
}
