import type { Filter, PageEnvelope } from "@/context/shared/Search/domain";
import type { SortDirection } from "@/context/shared/domain/types/sorting";
import type { Bank } from "./Bank";

export interface BankInput {
  name: string;
  shortName: string;
}

/**
 * Server-side sort for the banks list. `field` is the public sort field
 * (`name` | `shortName` | `createdAt` | `updatedAt`); the wire `direction`
 * token is uppercased by the adapter (the API enum is `ASC`/`DESC`).
 */
export interface BankSort {
  field: string;
  direction: SortDirection;
}

/**
 * A server-driven banks search request: generic filters, optional sort, and a
 * page size. It only STARTS a search (first page) or re-runs it after a query
 * change — cursor-only navigation is deliberately NOT expressed here. There is
 * no `page` and no `cursor`: continuing to the next/prev page is
 * `BankSearchNavigator.follow(link)`, which forwards a server-issued link
 * verbatim (W11), so the cursor never enters this domain port.
 */
export interface BankSearchCriteria {
  filters: Filter[];
  sort: BankSort | null;
  limit: number;
}

/**
 * One page of banks plus the cursor-only pagination envelope. Items travel in
 * `banks`; `hasNext`/`hasPrev`/`count` and the verbatim navigation `links` come
 * from {@link PageEnvelope}. There is no page number and no raw cursor — the
 * client navigates by replaying `links` verbatim.
 */
export type BankSearchPage = { banks: Bank[] } & PageEnvelope;

export interface BankRepository {
  search(criteria: BankSearchCriteria): Promise<BankSearchPage>;
  find(id: string): Promise<Bank>;
  create(input: BankInput): Promise<Bank>;
  update(id: string, input: BankInput): Promise<Bank>;
  delete(id: string): Promise<void>;
}
