import type { Filter, PageEnvelope } from "@/context/shared/Search/domain";
import type { SortDirection } from "@/context/shared/domain/types/sorting";
import type { BankAccount } from "./BankAccount";

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
 * Read-only domain port for a bank's associated accounts (CE-4: this surface
 * never writes). `search` starts a search for a given bank id; navigation is the
 * application-layer `BankAccountSearchNavigator`. No create/update/delete is
 * declared — the read context has zero write capability.
 */
export interface BankAccountRepository {
  search(bankId: string, criteria: BankAccountSearchCriteria): Promise<BankAccountSearchPage>;
}
