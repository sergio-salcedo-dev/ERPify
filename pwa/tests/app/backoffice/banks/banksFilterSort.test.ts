import { describe, expect, it } from "vitest";
import {
  BANKS_SORTABLE_COLUMNS,
  DEFAULT_SORT,
  EMPTY_FILTER,
  buildActiveFilterChips,
  countPanelFilters,
  hasActiveFilter,
  hasActivePanelFilter,
  isDefaultSort,
} from "@/app/backoffice/banks/_lib/banksFilterSort";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";

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

describe("buildActiveFilterChips", () => {
  it("returns no chips for the empty filter at the default sort", () => {
    expect(buildActiveFilterChips(EMPTY_FILTER, DEFAULT_SORT, dateTimeProvider)).toEqual([]);
  });

  it("labels name and code chips", () => {
    const chips = buildActiveFilterChips(
      { ...EMPTY_FILTER, name: "Acme", shortName: "ACM" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(chips).toEqual([
      { key: "name", label: "Name: Acme" },
      { key: "shortName", label: "Code: ACM" },
    ]);
  });

  it("renders an adaptive created chip (range / after / before) as dd/mm/yyyy", () => {
    const range = buildActiveFilterChips(
      { ...EMPTY_FILTER, createdFrom: "2026-06-01", createdTo: "2026-06-30" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(range[0]).toEqual({ key: "created", label: "Created: 01/06/2026 – 30/06/2026" });

    const from = buildActiveFilterChips(
      { ...EMPTY_FILTER, createdFrom: "2026-06-01" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(from[0]).toEqual({ key: "created", label: "Created after: 01/06/2026" });

    const to = buildActiveFilterChips(
      { ...EMPTY_FILTER, createdTo: "2026-06-30" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(to[0]).toEqual({ key: "created", label: "Created before: 30/06/2026" });
  });

  it("drops an unparseable created bound instead of echoing the raw value", () => {
    const bothInvalid = buildActiveFilterChips(
      { ...EMPTY_FILTER, createdFrom: "2026-13-99", createdTo: "nope" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(bothInvalid.some((chip) => chip.key === "created")).toBe(false);

    const validFrom = buildActiveFilterChips(
      { ...EMPTY_FILTER, createdFrom: "2026-06-01", createdTo: "2026-13-99" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(validFrom[0]).toEqual({ key: "created", label: "Created after: 01/06/2026" });

    const validTo = buildActiveFilterChips(
      { ...EMPTY_FILTER, createdFrom: "bad", createdTo: "2026-06-30" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(validTo[0]).toEqual({ key: "created", label: "Created before: 30/06/2026" });
  });

  it("normalises an inverted created range to chronological order", () => {
    const inverted = buildActiveFilterChips(
      { ...EMPTY_FILTER, createdFrom: "2026-06-30", createdTo: "2026-06-01" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(inverted[0]).toEqual({ key: "created", label: "Created: 01/06/2026 – 30/06/2026" });
  });

  it("adds a sort chip only when sort drifts from the default", () => {
    expect(
      buildActiveFilterChips(
        EMPTY_FILTER,
        { columnId: "createdAt", direction: "desc" },
        dateTimeProvider,
      ),
    ).toEqual([{ key: "sort", label: "Sorted: Created ↓" }]);
    expect(buildActiveFilterChips(EMPTY_FILTER, null, dateTimeProvider)).toEqual([
      { key: "sort", label: "Unsorted" },
    ]);
    expect(buildActiveFilterChips(EMPTY_FILTER, DEFAULT_SORT, dateTimeProvider)).toEqual([]);
  });

  it("treats whitespace-only text filters as inactive", () => {
    expect(
      buildActiveFilterChips(
        { ...EMPTY_FILTER, name: "  ", shortName: " " },
        DEFAULT_SORT,
        dateTimeProvider,
      ),
    ).toEqual([]);
  });
});
