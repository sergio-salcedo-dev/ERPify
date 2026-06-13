import { inject, injectable } from "inversify";
import type {
  BankAccountRepository,
  BankAccountSearchCriteria,
  BankAccountSearchPage,
} from "../domain/BankAccountRepository";

@injectable()
export class SearchBankAccounts {
  constructor(
    @inject("BackOfficeBankAccountRepository")
    private readonly repository: BankAccountRepository,
  ) {}

  async run(bankId: string, criteria: BankAccountSearchCriteria): Promise<BankAccountSearchPage> {
    return this.repository.search(bankId, criteria);
  }
}
