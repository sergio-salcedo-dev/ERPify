import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { DataTableSort } from "@/components/erpify";

export interface BanksFilter {
  name: string;
  shortName: string;
  createdFrom: string;
  createdTo: string;
}

export type BanksSort = DataTableSort | null;

export const EMPTY_FILTER: BanksFilter = {
  name: "",
  shortName: "",
  createdFrom: "",
  createdTo: "",
};

export function hasActiveFilter(filter: BanksFilter): boolean {
  return Boolean(
    filter.name.trim() || filter.shortName.trim() || filter.createdFrom || filter.createdTo,
  );
}

function containsCi(haystack: string, needle: string): boolean {
  if (!needle) return true;
  return haystack.toLowerCase().includes(needle.toLowerCase());
}

function startOfDay(yyyymmdd: string): number {
  if (!yyyymmdd) return Number.NEGATIVE_INFINITY;
  const ts = new Date(`${yyyymmdd}T00:00:00`).getTime();
  return Number.isNaN(ts) ? Number.NEGATIVE_INFINITY : ts;
}

function endOfDay(yyyymmdd: string): number {
  if (!yyyymmdd) return Number.POSITIVE_INFINITY;
  const ts = new Date(`${yyyymmdd}T23:59:59.999`).getTime();
  return Number.isNaN(ts) ? Number.POSITIVE_INFINITY : ts;
}

function rowTimestamp(iso: string): number {
  const ts = Date.parse(iso);
  return ts;
}

export function applyFilters(banks: readonly Bank[], filter: BanksFilter): Bank[] {
  const name = filter.name.trim();
  const shortName = filter.shortName.trim();
  const fromTs = startOfDay(filter.createdFrom);
  const toTs = endOfDay(filter.createdTo);
  const rangeActive = Boolean(filter.createdFrom || filter.createdTo);

  return banks.filter((bank) => {
    if (!containsCi(bank.name, name)) return false;
    if (!containsCi(bank.shortName, shortName)) return false;
    if (rangeActive) {
      const ts = rowTimestamp(bank.createdAt);
      if (Number.isNaN(ts)) return false;
      if (ts < fromTs) return false;
      if (ts > toTs) return false;
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
    default:
      return banks.slice();
  }

  return sorted;
}
