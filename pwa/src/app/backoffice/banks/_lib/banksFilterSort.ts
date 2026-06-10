import type { DataTableSort } from "@/components/erpify";
import { SortDirection } from "@/context/shared/domain/types/sorting";

export interface BanksFilter {
  name: string;
  shortName: string;
  /** ISO `yyyy-mm-dd` (native date-picker format). Empty string means "no lower bound". */
  createdFrom: string;
  /** ISO `yyyy-mm-dd` (native date-picker format). Empty string means "no upper bound". */
  createdTo: string;
}

export type BanksSort = DataTableSort | null;

/** Default sort: alphabetical by name, A → Z. */
export const DEFAULT_SORT: BanksSort = { columnId: "name", direction: SortDirection.ASC };

export type BanksSortableColumn = "shortName" | "name" | "createdAt" | "updatedAt";

/** The set of columns the user can sort by, paired with the labels shown in the filters panel. */
export const BANKS_SORTABLE_COLUMNS: ReadonlyArray<{ id: BanksSortableColumn; label: string }> = [
  { id: "shortName", label: "Short name" },
  { id: "name", label: "Name" },
  { id: "createdAt", label: "Created" },
  { id: "updatedAt", label: "Updated" },
];

export function isDefaultSort(sort: BanksSort): boolean {
  if (!sort || !DEFAULT_SORT) return sort === DEFAULT_SORT;
  return sort.columnId === DEFAULT_SORT.columnId && sort.direction === DEFAULT_SORT.direction;
}

export const EMPTY_FILTER: BanksFilter = {
  name: "",
  shortName: "",
  createdFrom: "",
  createdTo: "",
};

/**
 * Number of populated fields among the panel-hosted filters (short name +
 * created range). The name search lives in the always-visible toolbar, so the
 * "Filters (n)" badge and the auto-open heuristic only count what a collapsed
 * panel would otherwise hide. Whitespace-only values count as inactive.
 */
export function countPanelFilters(filter: BanksFilter): number {
  let count = 0;
  if (filter.shortName.trim()) count += 1;
  if (filter.createdFrom.trim()) count += 1;
  if (filter.createdTo.trim()) count += 1;
  return count;
}

export function hasActivePanelFilter(filter: BanksFilter): boolean {
  return countPanelFilters(filter) > 0;
}

/**
 * True when ANY filter is populated — the toolbar name search included.
 * Drives the Reset button: Reset clears `name` too (state is shared, only
 * its placement moved to the toolbar), so it must be reachable when the
 * search is the only active filter. The badge keeps using the panel-only
 * count above.
 */
export function hasActiveFilter(filter: BanksFilter): boolean {
  return Boolean(filter.name.trim()) || hasActivePanelFilter(filter);
}
