import { describe, expect, it } from "vitest";
import {
  BANKS_SORTABLE_COLUMNS,
  DEFAULT_SORT,
  EMPTY_FILTER,
  countPanelFilters,
  hasActiveFilter,
  hasActivePanelFilter,
  isDefaultSort,
} from "@/app/backoffice/banks/_lib/banksFilterSort";

describe("DEFAULT_SORT", () => {
  it("is alphabetical name ascending", () => {
    expect(DEFAULT_SORT).toEqual({ columnId: "name", direction: "asc" });
  });
});

describe("BANKS_SORTABLE_COLUMNS", () => {
  it("matches the sortable columns rendered by the table headers", () => {
    expect(BANKS_SORTABLE_COLUMNS.map((column) => column.id)).toEqual([
      "shortName",
      "name",
      "createdAt",
      "updatedAt",
    ]);
  });

  it("exposes a human label for every option (used by the filters panel select)", () => {
    for (const column of BANKS_SORTABLE_COLUMNS) {
      expect(column.label.length).toBeGreaterThan(0);
    }
  });

  it("labels the shortName column 'Code' (the unique upper-case bank code)", () => {
    const column = BANKS_SORTABLE_COLUMNS.find((c) => c.id === "shortName");
    expect(column?.label).toBe("Code");
  });
});

describe("isDefaultSort", () => {
  it("returns true for the DEFAULT_SORT reference", () => {
    expect(isDefaultSort(DEFAULT_SORT)).toBe(true);
  });

  it("returns true for a fresh equivalent object", () => {
    expect(isDefaultSort({ columnId: "name", direction: "asc" })).toBe(true);
  });

  it("returns false for a non-default direction", () => {
    expect(isDefaultSort({ columnId: "name", direction: "desc" })).toBe(false);
  });

  it("returns false for a different column", () => {
    expect(isDefaultSort({ columnId: "createdAt", direction: "asc" })).toBe(false);
  });

  it("returns false when sort is null (user disabled sorting)", () => {
    expect(isDefaultSort(null)).toBe(false);
  });
});

describe("countPanelFilters", () => {
  it("counts only the panel-hosted fields (short name + created range)", () => {
    expect(countPanelFilters(EMPTY_FILTER)).toBe(0);
    expect(countPanelFilters({ ...EMPTY_FILTER, name: "acme" })).toBe(0);
    expect(countPanelFilters({ ...EMPTY_FILTER, shortName: "ACM" })).toBe(1);
    expect(
      countPanelFilters({
        ...EMPTY_FILTER,
        shortName: "ACM",
        createdFrom: "2026-01-01",
        createdTo: "2026-02-01",
      }),
    ).toBe(3);
  });

  it("treats whitespace-only values as inactive", () => {
    expect(countPanelFilters({ ...EMPTY_FILTER, shortName: "  " })).toBe(0);
    expect(countPanelFilters({ ...EMPTY_FILTER, createdFrom: " " })).toBe(0);
  });
});

describe("hasActivePanelFilter", () => {
  it("is false when only the toolbar search (name) is set", () => {
    expect(hasActivePanelFilter({ ...EMPTY_FILTER, name: "acme" })).toBe(false);
    expect(hasActivePanelFilter({ ...EMPTY_FILTER, createdFrom: "2026-01-01" })).toBe(true);
  });
});

describe("hasActiveFilter", () => {
  it("is true for the toolbar search (name) as well as panel fields", () => {
    expect(hasActiveFilter(EMPTY_FILTER)).toBe(false);
    expect(hasActiveFilter({ ...EMPTY_FILTER, name: "acme" })).toBe(true);
    expect(hasActiveFilter({ ...EMPTY_FILTER, shortName: "ACM" })).toBe(true);
  });

  it("treats a whitespace-only name as inactive", () => {
    expect(hasActiveFilter({ ...EMPTY_FILTER, name: "  " })).toBe(false);
  });
});
