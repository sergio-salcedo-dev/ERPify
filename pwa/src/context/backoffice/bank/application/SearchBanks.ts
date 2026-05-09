import { inject, injectable } from "inversify";
import type { BankRepository, BankSearchPage } from "../domain/BankRepository";

@injectable()
export class SearchBanks {
  constructor(@inject("BackOfficeBankRepository") private readonly repository: BankRepository) {}

  async run(): Promise<BankSearchPage> {
    return this.repository.search();
  }
}
