import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { DataTableSort } from "@/components/erpify";
import { endOfDdMmYyyy, startOfDdMmYyyy } from "./formatDate";

export interface BanksFilter {
  name: string;
  shortName: string;
  /** `dd/mm/yyyy`. Empty string means "no lower bound". */
  createdFrom: string;
  /** `dd/mm/yyyy`. Empty string means "no upper bound". */
  createdTo: string;
}

export type BanksSort = DataTableSort | null;

/** Default sort: alphabetical by name, A → Z. */
export const DEFAULT_SORT: BanksSort = { columnId: "name", direction: "asc" };

export const EMPTY_FILTER: BanksFilter = {
  name: "",
  shortName: "",
  createdFrom: "",
  createdTo: "",
};

export function hasActiveFilter(filter: BanksFilter): boolean {
  return Boolean(
    filter.name.trim() ||
    filter.shortName.trim() ||
    filter.createdFrom.trim() ||
    filter.createdTo.trim(),
  );
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
  const fromTs = startOfDdMmYyyy(filter.createdFrom);
  const toTs = endOfDdMmYyyy(filter.createdTo);
  const fromActive = filter.createdFrom.trim().length > 0;
  const toActive = filter.createdTo.trim().length > 0;
  // A range field with text the user is still typing (e.g. "01/02") is treated
  // as inactive — the filter only kicks in once a complete dd/mm/yyyy parses.
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

  const direction = sort.direction === "desc" ? -1 : 1;
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
