import { inject, injectable } from "inversify";
import type { BankAccount } from "../domain/BankAccount";
import type { BankAccountRepository } from "../domain/BankAccountRepository";

@injectable()
export class FindBankAccount {
  constructor(
    @inject("BackOfficeBankAccountRepository")
    private readonly repository: BankAccountRepository,
  ) {}

  async run(id: string): Promise<BankAccount> {
    return this.repository.find(id);
  }
}
