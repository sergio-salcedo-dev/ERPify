import type { DataTableSort } from "@/components/erpify";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";

/**
 * UI filter state for the global accounts list. The API contract is the
 * authority for what is filterable: `holderName` (the toolbar search), `alias`,
 * and `bank` (a substring match over the owning bank's name or short code). IBAN
 * is deliberately not filterable — it renders masked but a filter would carry the
 * integral IBAN (PII) in the GET query string and thus into server/proxy access
 * logs. No date range here — the collection view drops audit timestamps (they are
 * keyset paging machinery, sortable but not displayed).
 */
export interface BankAccountsFilter {
  holderName: string;
  alias: string;
  bank: string;
}

export type BankAccountsSort = DataTableSort | null;

/** Default sort: alphabetical by holder, A → Z. */
export const DEFAULT_SORT: BankAccountsSort = {
  columnId: "holderName",
  direction: SortDirection.ASC,
};

/**
 * Server-side sortable fields (the API contract): `holderName` and the two
 * audit timestamps. `createdAt`/`updatedAt` are sortable even though the row
 * never renders them — they exist only in the sort dropdown.
 */
export type BankAccountsSortableColumn = "holderName" | "createdAt" | "updatedAt";

export const BANK_ACCOUNTS_SORTABLE_COLUMNS: ReadonlyArray<{
  id: BankAccountsSortableColumn;
  label: string;
}> = [
  { id: "holderName", label: "Holder" },
  { id: "createdAt", label: "Created" },
  { id: "updatedAt", label: "Updated" },
];

export function isDefaultSort(sort: BankAccountsSort): boolean {
  if (!sort || !DEFAULT_SORT) return sort === DEFAULT_SORT;
  return sort.columnId === DEFAULT_SORT.columnId && sort.direction === DEFAULT_SORT.direction;
}

export const EMPTY_FILTER: BankAccountsFilter = {
  holderName: "",
  alias: "",
  bank: "",
};

/**
 * Number of populated fields among the panel-hosted filters (alias + bank).
 * The holder search lives in the always-visible toolbar, so the "Filters (n)"
 * badge only counts what a collapsed panel would otherwise hide. Whitespace-only
 * values count as inactive.
 */
export function countPanelFilters(filter: BankAccountsFilter): number {
  let count = 0;
  if (filter.alias.trim()) count += 1;
  if (filter.bank.trim()) count += 1;
  return count;
}

export function hasActivePanelFilter(filter: BankAccountsFilter): boolean {
  return countPanelFilters(filter) > 0;
}

/**
 * True when ANY filter is populated — the toolbar holder search included.
 * Drives the Reset button, which clears `holderName` too, so it must be
 * reachable when the search is the only active filter.
 */
export function hasActiveFilter(filter: BankAccountsFilter): boolean {
  return Boolean(filter.holderName.trim()) || hasActivePanelFilter(filter);
}

export type FilterChipKey = "holderName" | "alias" | "bank" | "sort";

export interface FilterChipDescriptor {
  key: FilterChipKey;
  /** Human label rendered inside the chip, e.g. `Holder: Acme`. */
  label: string;
}

const SORT_ARROW: Record<SortDirection, string> = {
  [SortDirection.ASC]: "↑",
  [SortDirection.DESC]: "↓",
};

/**
 * Descriptors for the always-visible active-filter chip bar. The holder search
 * lives in the toolbar but still earns a chip so the bar is a complete summary.
 * The sort chip appears for any drift from {@link DEFAULT_SORT}, including the
 * explicit "None" (`null`), which reads as "Unsorted".
 */
export function buildActiveFilterChips(
  filter: BankAccountsFilter,
  sort: BankAccountsSort,
): FilterChipDescriptor[] {
  const chips: FilterChipDescriptor[] = [];
  if (filter.holderName.trim())
    chips.push({ key: "holderName", label: `Holder: ${filter.holderName.trim()}` });
  if (filter.alias.trim()) chips.push({ key: "alias", label: `Alias: ${filter.alias.trim()}` });
  if (filter.bank.trim()) chips.push({ key: "bank", label: `Bank: ${filter.bank.trim()}` });

  if (!isDefaultSort(sort)) {
    if (sort) {
      const column = BANK_ACCOUNTS_SORTABLE_COLUMNS.find((c) => c.id === sort.columnId);
      chips.push({
        key: "sort",
        label: `Sorted: ${column?.label ?? sort.columnId} ${SORT_ARROW[sort.direction]}`,
      });
    } else {
      chips.push({ key: "sort", label: "Unsorted" });
    }
  }
  return chips;
}
