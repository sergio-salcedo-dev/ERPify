import { inject, injectable } from "inversify";
import type {
  CrudRepository,
  ResourceSearchCriteria,
  ResourceSearchPage,
} from "@/context/shared/resource/domain/CrudRepository";
import type { BankAccount } from "../domain/BankAccount";
import type { BankAccountCollectionRow } from "../domain/BankAccountCollectionRow";
import type {
  BankAccountRepository,
  CreateBankAccountInput,
} from "../domain/BankAccountRepository";
import { toResourcePage } from "./bankAccountResourcePage";

/**
 * The standalone account endpoints (`find`/`create`/`update`) return the account
 * entity, which carries no owning-bank name — that read-composition exists only
 * on the collection projection built by the server JOIN. This adapts the entity
 * to the row shape the generic toolkit is typed over; the missing bank identity
 * is left blank because no consumer renders these results (see below).
 */
function toCollectionRow(account: BankAccount): BankAccountCollectionRow {
  return {
    id: account.id,
    bankId: account.bankId ?? "",
    bankName: "",
    bankShortName: "",
    holderName: account.holderName,
    iban: account.iban,
    bic: account.bic,
    alias: account.alias,
    currency: account.currency,
    status: account.status,
  };
}

/**
 * Adapts the bank-account {@link BankAccountRepository} to the generic
 * {@link CrudRepository} the shared `useResourceList` toolkit consumes over the
 * global collection: `search` runs the cross-bank `searchAll` and remaps its
 * `{ rows }` page to `{ items }`; `delete` delegates verbatim. `find`/`create`/
 * `update` delegate too, but their results are never rendered by the global list
 * — `find` backs only the bulk-delete existence probe (its resolved value is
 * discarded; only presence vs a 404 matters), and standalone create/edit are
 * routed to the nested per-bank flow — so the account entity they return is
 * adapted to the row shape with the JOIN-only bank name left blank. Errors
 * propagate untouched.
 */
@injectable()
export class BankAccountCrudRepository implements CrudRepository<
  BankAccountCollectionRow,
  CreateBankAccountInput
> {
  constructor(
    @inject("BackOfficeBankAccountRepository")
    private readonly accounts: BankAccountRepository,
  ) {}

  async search(
    criteria: ResourceSearchCriteria,
  ): Promise<ResourceSearchPage<BankAccountCollectionRow>> {
    return toResourcePage(await this.accounts.searchAll(criteria));
  }

  async find(id: string): Promise<BankAccountCollectionRow> {
    return toCollectionRow(await this.accounts.find(id));
  }

  async create(input: CreateBankAccountInput): Promise<BankAccountCollectionRow> {
    return toCollectionRow(await this.accounts.create(input));
  }

  async update(id: string, input: CreateBankAccountInput): Promise<BankAccountCollectionRow> {
    return toCollectionRow(await this.accounts.update(id, input));
  }

  delete(id: string): Promise<void> {
    return this.accounts.delete(id);
  }
}
