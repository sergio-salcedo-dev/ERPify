import { inject, injectable } from "inversify";
import type { BankAccount } from "../domain/BankAccount";
import type {
  BankAccountRepository,
  UpdateBankAccountInput,
} from "../domain/BankAccountRepository";

@injectable()
export class UpdateBankAccount {
  constructor(
    @inject("BackOfficeBankAccountRepository")
    private readonly repository: BankAccountRepository,
  ) {}

  async run(id: string, input: UpdateBankAccountInput): Promise<BankAccount> {
    return this.repository.update(id, input);
  }
}
