import type { Filter } from "@/context/shared/search/domain";
import type { BankSort } from "@/context/backoffice/bank/domain/BankRepository";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import type { BanksFilter, BanksSort } from "./banksFilterSort";

const CREATED_AT_FIELD = "createdAt";

/**
 * Maps the banks UI filter state to the generic `Filter[]` vocabulary the API
 * understands. Mirrors the old client-side `applyFilters`: `name`/`shortName`
 * become `contains`, the created range becomes inclusive `gte`/`lte` bounds on
 * `createdAt`. Blank, whitespace-only, or unparseable (mid-edit) values are
 * omitted, so they never reach the server as a filter.
 */
export function toBankFilters(filter: BanksFilter): Filter[] {
  const filters: Filter[] = [];

  const name = filter.name.trim();
  if (name) {
    filters.push({ field: "name", operator: "contains", value: name });
  }

  const shortName = filter.shortName.trim();
  if (shortName) {
    filters.push({ field: "shortName", operator: "contains", value: shortName });
  }

  const from = isoBound(filter.createdFrom, "start");
  if (from) {
    filters.push({ field: CREATED_AT_FIELD, operator: "gte", value: from });
  }

  const to = isoBound(filter.createdTo, "end");
  if (to) {
    filters.push({ field: CREATED_AT_FIELD, operator: "lte", value: to });
  }

  return filters;
}

/** Maps the UI sort (table column id + direction) to the domain sort, or null. */
export function toBankSort(sort: BanksSort): BankSort | null {
  if (!sort) {
    return null;
  }
  return { field: sort.columnId, direction: sort.direction };
}

/**
 * Converts a native `yyyy-mm-dd` date-picker value into an inclusive ISO-8601
 * bound: start-of-day for the lower bound, end-of-day for the upper. Returns
 * null when empty or not a complete calendar day (so a half-typed value is
 * treated as "no bound", matching the old client behaviour).
 */
// The native date picker emits "" or a complete `yyyy-mm-dd`; a half-typed value
// like `2026-01` must count as "no bound" (parseISO would otherwise accept it as
// January). This mirrors the old client's "complete calendar day only" rule.
const COMPLETE_ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

function isoBound(value: string, edge: "start" | "end"): string | null {
  const trimmed = value.trim();
  if (!COMPLETE_ISO_DATE.test(trimmed)) {
    return null;
  }
  const date = dateTimeProvider.parseISO(trimmed);
  if (!date) {
    return null;
  }
  const bounded =
    edge === "start" ? dateTimeProvider.startOfDay(date) : dateTimeProvider.endOfDay(date);
  return dateTimeProvider.formatToISO(bounded);
}
