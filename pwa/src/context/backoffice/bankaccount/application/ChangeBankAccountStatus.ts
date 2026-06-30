import { inject, injectable } from "inversify";
import type { BankAccount, BankAccountStatus } from "../domain/BankAccount";
import type { BankAccountRepository } from "../domain/BankAccountRepository";

/**
 * Transitions an account's lifecycle status through the dedicated endpoint, kept
 * separate from the descriptive {@link UpdateBankAccount} so a status change is an
 * explicit intent. A transition to the current status is an idempotent no-op
 * server-side.
 */
@injectable()
export class ChangeBankAccountStatus {
  constructor(
    @inject("BackOfficeBankAccountRepository")
    private readonly repository: BankAccountRepository,
  ) {}

  async run(id: string, status: BankAccountStatus): Promise<BankAccount> {
    return this.repository.changeStatus(id, status);
  }
}
