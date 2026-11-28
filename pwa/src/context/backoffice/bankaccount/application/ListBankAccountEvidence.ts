import { inject, injectable } from "inversify";
import type {
  BankAccountEvidence,
  BankAccountEvidenceRepository,
} from "../domain/BankAccountEvidence";

@injectable()
export class ListBankAccountEvidence {
  constructor(
    @inject("BackOfficeBankAccountEvidenceRepository")
    private readonly repository: BankAccountEvidenceRepository,
  ) {}

  run(accountId: string): Promise<BankAccountEvidence[]> {
    return this.repository.list(accountId);
  }
}
