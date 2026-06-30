import { inject, injectable } from "inversify";
import type { BankAccountRepository } from "../domain/BankAccountRepository";

@injectable()
export class DeleteBankAccount {
  constructor(
    @inject("BackOfficeBankAccountRepository")
    private readonly repository: BankAccountRepository,
  ) {}

  async run(id: string): Promise<void> {
    return this.repository.delete(id);
  }
}
