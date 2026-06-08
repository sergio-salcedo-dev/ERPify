import type { Filter } from "@/context/shared/domain/Search";
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
 * A server-driven banks search request: generic filters, optional sort, and
 * keyset pagination. `cursor` is the opaque cursor from the previous page
 * (only meaningful when `page > 1`); `limit` is the page size.
 */
export interface BankSearchCriteria {
  filters: Filter[];
  sort: BankSort | null;
  page: number;
  cursor?: string;
  limit: number;
}

/**
 * One page of banks plus the keyset pagination envelope. `cursor` is opaque and
 * fed back verbatim to navigate; `totalCount` comes from the detailed pagination
 * mode (null if the server did not compute it).
 */
export interface BankSearchPage {
  banks: Bank[];
  cursor: string;
  currentPage: number;
  hasMorePages: boolean;
  totalCount: number | null;
}

export interface BankRepository {
  search(criteria: BankSearchCriteria): Promise<BankSearchPage>;
  find(id: string): Promise<Bank>;
  create(input: BankInput): Promise<Bank>;
  update(id: string, input: BankInput): Promise<Bank>;
  delete(id: string): Promise<void>;
}
