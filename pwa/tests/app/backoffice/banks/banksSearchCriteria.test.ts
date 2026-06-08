import { describe, expect, it } from "vitest";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import { EMPTY_FILTER } from "@/app/backoffice/banks/_lib/banksFilterSort";
import { toBankFilters, toBankSort } from "@/app/backoffice/banks/_lib/banksSearchCriteria";

describe("toBankFilters", () => {
  it("maps name and short name to contains filters", () => {
    const filters = toBankFilters({ ...EMPTY_FILTER, name: "banc", shortName: "AC" });

    expect(filters).toEqual([
      { field: "name", operator: "contains", value: "banc" },
      { field: "shortName", operator: "contains", value: "AC" },
    ]);
  });

  it("maps the created range to inclusive gte/lte bounds on createdAt", () => {
    const filters = toBankFilters({
      ...EMPTY_FILTER,
      createdFrom: "2026-01-01",
      createdTo: "2026-01-31",
    });

    const from = filters.find((f) => f.operator === "gte");
    const to = filters.find((f) => f.operator === "lte");
    expect(from).toBeDefined();
    expect(to).toBeDefined();
    expect(from?.field).toBe("createdAt");
    expect(to?.field).toBe("createdAt");
    // start-of-day lower bound precedes the end-of-day upper bound
    expect(String(from?.value) <= String(to?.value)).toBe(true);
  });

  it("omits blank and whitespace-only fields", () => {
    expect(toBankFilters(EMPTY_FILTER)).toEqual([]);
    expect(toBankFilters({ ...EMPTY_FILTER, name: "   ", shortName: "\t" })).toEqual([]);
  });

  it("omits an incomplete (unparseable) date value", () => {
    expect(toBankFilters({ ...EMPTY_FILTER, createdFrom: "2026-01" })).toEqual([]);
  });
});

describe("toBankSort", () => {
  it("returns null for no sort", () => {
    expect(toBankSort(null)).toBeNull();
  });

  it("maps the table column id and direction to a domain sort", () => {
    expect(toBankSort({ columnId: "createdAt", direction: SortDirection.DESC })).toEqual({
      field: "createdAt",
      direction: SortDirection.DESC,
    });
  });
});
