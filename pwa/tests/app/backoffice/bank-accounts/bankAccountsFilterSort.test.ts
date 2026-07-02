import { describe, expect, it } from "vitest";
import {
  buildActiveFilterChips,
  countPanelFilters,
  DEFAULT_SORT,
  EMPTY_FILTER,
  hasActiveFilter,
  hasActivePanelFilter,
  isDefaultSort,
  type BankAccountsFilter,
} from "@/app/backoffice/bank-accounts/_lib/bankAccountsFilterSort";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";

const filterWith = (overrides: Partial<BankAccountsFilter>): BankAccountsFilter => ({
  ...EMPTY_FILTER,
  ...overrides,
});

describe("bankAccountsFilterSort — isDefaultSort", () => {
  it("is true only for the exact default column+direction", () => {
    expect(isDefaultSort(DEFAULT_SORT)).toBe(true);
    expect(isDefaultSort({ columnId: "holderName", direction: SortDirection.DESC })).toBe(false);
    expect(isDefaultSort({ columnId: "createdAt", direction: SortDirection.ASC })).toBe(false);
  });

  it("treats an explicit null (Unsorted) as a drift from the default", () => {
    expect(isDefaultSort(null)).toBe(false);
  });
});

describe("bankAccountsFilterSort — active-filter predicates", () => {
  it("counts only the panel-hosted fields (alias + bank), ignoring whitespace", () => {
    expect(countPanelFilters(EMPTY_FILTER)).toBe(0);
    expect(countPanelFilters(filterWith({ alias: "Payroll" }))).toBe(1);
    expect(countPanelFilters(filterWith({ alias: "Payroll", bank: "Acme" }))).toBe(2);
    expect(countPanelFilters(filterWith({ bank: "   " }))).toBe(0);
  });

  it("hasActivePanelFilter reflects the panel count", () => {
    expect(hasActivePanelFilter(EMPTY_FILTER)).toBe(false);
    expect(hasActivePanelFilter(filterWith({ bank: "Acme" }))).toBe(true);
  });

  it("hasActiveFilter includes the toolbar holder search", () => {
    expect(hasActiveFilter(EMPTY_FILTER)).toBe(false);
    expect(hasActiveFilter(filterWith({ holderName: "Alice" }))).toBe(true);
    expect(hasActiveFilter(filterWith({ holderName: "   " }))).toBe(false);
  });
});

describe("bankAccountsFilterSort — buildActiveFilterChips", () => {
  it("emits a trimmed chip per populated filter field", () => {
    const chips = buildActiveFilterChips(
      filterWith({ holderName: " Alice ", alias: "Payroll", bank: "Acme" }),
      DEFAULT_SORT,
    );

    expect(chips.map((chip) => chip.label)).toEqual([
      "Holder: Alice",
      "Alias: Payroll",
      "Bank: Acme",
    ]);
    expect(chips.some((chip) => chip.key === "sort")).toBe(false);
  });

  it("labels a drifted sort with the column name and a direction arrow", () => {
    const chips = buildActiveFilterChips(EMPTY_FILTER, {
      columnId: "createdAt",
      direction: SortDirection.DESC,
    });

    expect(chips).toEqual([{ key: "sort", label: "Sorted: Created ↓" }]);
  });

  it("falls back to the raw column id when the sort column is unknown", () => {
    const chips = buildActiveFilterChips(EMPTY_FILTER, {
      columnId: "mystery",
      direction: SortDirection.ASC,
    });

    expect(chips).toEqual([{ key: "sort", label: "Sorted: mystery ↑" }]);
  });

  it("labels an explicit null sort as Unsorted", () => {
    const chips = buildActiveFilterChips(EMPTY_FILTER, null);

    expect(chips).toEqual([{ key: "sort", label: "Unsorted" }]);
  });
});
