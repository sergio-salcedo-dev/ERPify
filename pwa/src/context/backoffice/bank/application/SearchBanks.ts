import { inject, injectable } from "inversify";
import type { BankRepository, BankSearchCriteria, BankSearchPage } from "../domain/BankRepository";

@injectable()
export class SearchBanks {
  constructor(@inject("BackOfficeBankRepository") private readonly repository: BankRepository) {}

  async run(criteria: BankSearchCriteria): Promise<BankSearchPage> {
    return this.repository.search(criteria);
  }
}
