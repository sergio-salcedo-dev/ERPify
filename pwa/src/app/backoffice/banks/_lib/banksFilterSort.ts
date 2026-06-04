import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { DataTableSort } from "@/components/erpify";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
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
 * panel would otherwise hide. Whitespace-only values count as inactive
 * (mirrors `applyFilters`).
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

function containsCi(haystack: string, needle: string): boolean {
  if (!needle) return true;
  return haystack.toLowerCase().includes(needle.toLowerCase());
}

function rowTimestamp(iso: string): number {
  return Date.parse(iso);
}

export function applyFilters(banks: readonly Bank[], filter: BanksFilter): Bank[] {
  const name = filter.name.trim();
  const shortName = filter.shortName.trim();
  const fromTs = dateTimeProvider.parseIsoDateToStartTimestamp(filter.createdFrom);
  const toTs = dateTimeProvider.parseIsoDateToEndTimestamp(filter.createdTo);
  const fromActive = filter.createdFrom.trim().length > 0;
  const toActive = filter.createdTo.trim().length > 0;
  // A range field whose value doesn't parse to a real calendar day (e.g.
  // mid-edit `2026-02`) is treated as inactive — the filter only kicks in
  // once the native picker emits a complete yyyy-mm-dd.
  const rangeActive = (fromActive && fromTs !== null) || (toActive && toTs !== null);

  return banks.filter((bank) => {
    if (!containsCi(bank.name, name)) return false;
    if (!containsCi(bank.shortName, shortName)) return false;
    if (rangeActive) {
      const ts = rowTimestamp(bank.createdAt);
      if (Number.isNaN(ts)) return false;
      if (fromTs !== null && ts < fromTs) return false;
      if (toTs !== null && ts > toTs) return false;
    }
    return true;
  });
}

const STRING_COLLATOR = new Intl.Collator("en", { sensitivity: "base" });

function compareString(a: string, b: string): number {
  return STRING_COLLATOR.compare(a, b);
}

function compareDate(a: string, b: string): number {
  const aTs = Date.parse(a);
  const bTs = Date.parse(b);
  const aBad = Number.isNaN(aTs);
  const bBad = Number.isNaN(bTs);
  if (aBad && bBad) return 0;
  if (aBad) return 1;
  if (bBad) return -1;
  return aTs - bTs;
}

export function applySort(banks: readonly Bank[], sort: BanksSort): Bank[] {
  if (!sort) return banks.slice();

  const direction = sort.direction === SortDirection.DESC ? -1 : 1;
  const sorted = banks.slice();

  switch (sort.columnId) {
    case "name":
      sorted.sort((a, b) => direction * compareString(a.name, b.name));
      break;
    case "shortName":
      sorted.sort((a, b) => direction * compareString(a.shortName, b.shortName));
      break;
    case "createdAt":
      sorted.sort((a, b) => direction * compareDate(a.createdAt, b.createdAt));
      break;
    case "updatedAt":
      sorted.sort((a, b) => direction * compareDate(a.updatedAt, b.updatedAt));
      break;
    default:
      return banks.slice();
  }

  return sorted;
}
