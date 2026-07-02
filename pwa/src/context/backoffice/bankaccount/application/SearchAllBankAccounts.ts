import { inject, injectable } from "inversify";
import type {
  BankAccountRepository,
  BankAccountSearchCriteria,
} from "../domain/BankAccountRepository";
import type { BankAccountCollectionPage } from "../domain/BankAccountCollectionRow";

/**
 * Global, cross-bank account search use case (mirrors `SearchBanks`): starts a
 * search over the `/bank-accounts` collection view, returning rows enriched with
 * the owning bank's name. Distinct from `SearchBankAccounts`, which is scoped to
 * a single bank's nested list.
 */
@injectable()
export class SearchAllBankAccounts {
  constructor(
    @inject("BackOfficeBankAccountRepository")
    private readonly repository: BankAccountRepository,
  ) {}

  async run(criteria: BankAccountSearchCriteria): Promise<BankAccountCollectionPage> {
    return this.repository.searchAll(criteria);
  }
}
