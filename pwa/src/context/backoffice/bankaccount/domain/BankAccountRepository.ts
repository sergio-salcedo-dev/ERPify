import type { Filter, PageEnvelope } from "@/context/shared/search/domain";
import type { SortDirection } from "@/context/shared/search/domain/SortDirection";
import type { BankAccount, BankAccountCurrency, BankAccountStatus } from "./BankAccount";
import type { BankAccountCollectionPage } from "./BankAccountCollectionRow";

/**
 * Editable core of a bank account, shared by create and update — mirroring the
 * API's `CreateBankAccountCommand` (adds `bankId`; status defaults to ACTIVE
 * server-side) and `UpdateBankAccountCommand` (descriptive fields only — the
 * lifecycle `status` is not editable here). `bic` / `alias` are nullable
 * optionals; `null` clears them.
 */
export interface BankAccountInput {
  holderName: string;
  iban: string;
  bic: string | null;
  alias: string | null;
  currency: BankAccountCurrency;
}

/** Create payload: the editable core plus the owning bank id (status defaults to ACTIVE server-side). */
export interface CreateBankAccountInput extends BankAccountInput {
  bankId: string;
}

/** Update payload: the descriptive core only — status transitions through {@link BankAccountRepository.changeStatus}. */
export type UpdateBankAccountInput = BankAccountInput;

/**
 * Server-side sort for a bank's accounts list. `field` is the public sort field;
 * the wire `direction` token is uppercased by the adapter (the API enum is
 * `ASC`/`DESC`).
 */
export interface BankAccountSort {
  field: string;
  direction: SortDirection;
}

/**
 * A server-driven accounts search request: generic filters, optional sort, and
 * a page size. It only STARTS a search (first page) or re-runs it after a query
 * change — cursor-only navigation is deliberately NOT expressed here. Continuing
 * to the next/prev page is `BankAccountSearchNavigator.follow(link)`, which
 * forwards a server-issued link verbatim, so the cursor never enters this port.
 */
export interface BankAccountSearchCriteria {
  filters: Filter[];
  sort: BankAccountSort | null;
  limit: number;
}

/**
 * One page of accounts plus the cursor-only pagination envelope. Items travel
 * in `accounts`; `hasNext`/`hasPrev`/`count` and the verbatim navigation `links`
 * come from {@link PageEnvelope}. There is no page number and no raw cursor.
 */
export type BankAccountSearchPage = { accounts: BankAccount[] } & PageEnvelope;

/**
 * Domain port for bank accounts. `search` starts a per-bank search and
 * `searchAll` starts the cross-bank global search (both navigated by the
 * application-layer navigators, which follow server links verbatim);
 * `find`/`create`/`update`/`delete` drive the standalone CRUD against the
 * `/bank-accounts` endpoints (the create carries `bankId` in the body, the rest
 * key off the account id). The 409 `bank-account-not-closed` precondition on
 * delete is the API's authoritative guard; the UI mirrors it optimistically off
 * the row's `status`.
 *
 * `searchAll` returns a dedicated {@link BankAccountCollectionPage} of
 * {@link import("./BankAccountCollectionRow").BankAccountCollectionRow} (each row
 * enriched with its owning bank's name via the server JOIN), NOT the account
 * entity — the per-bank `search` and the global `searchAll` are distinct read
 * views with distinct row shapes.
 */
export interface BankAccountRepository {
  search(bankId: string, criteria: BankAccountSearchCriteria): Promise<BankAccountSearchPage>;
  searchAll(criteria: BankAccountSearchCriteria): Promise<BankAccountCollectionPage>;
  find(id: string): Promise<BankAccount>;
  create(input: CreateBankAccountInput): Promise<BankAccount>;
  update(id: string, input: UpdateBankAccountInput): Promise<BankAccount>;
  changeStatus(id: string, status: BankAccountStatus): Promise<BankAccount>;
  delete(id: string): Promise<void>;
}
