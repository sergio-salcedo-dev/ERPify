import { inject, injectable } from "inversify";
import type { BankAccount } from "../domain/BankAccount";
import type {
  BankAccountRepository,
  CreateBankAccountInput,
} from "../domain/BankAccountRepository";

@injectable()
export class CreateBankAccount {
  constructor(
    @inject("BackOfficeBankAccountRepository")
    private readonly repository: BankAccountRepository,
  ) {}

  async run(input: CreateBankAccountInput): Promise<BankAccount> {
    return this.repository.create(input);
  }
}
