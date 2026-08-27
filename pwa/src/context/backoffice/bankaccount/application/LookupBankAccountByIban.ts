import { inject, injectable } from "inversify";
import type { BankAccountCollectionRow } from "../domain/BankAccountCollectionRow";
import type { BankAccountRepository } from "../domain/BankAccountRepository";

@injectable()
export class LookupBankAccountByIban {
  constructor(
    @inject("BackOfficeBankAccountRepository")
    private readonly repository: BankAccountRepository,
  ) {}

  async run(iban: string): Promise<BankAccountCollectionRow> {
    return this.repository.findByIban(iban);
  }
}
