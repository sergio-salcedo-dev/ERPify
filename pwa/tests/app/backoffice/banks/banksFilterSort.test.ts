import { describe, expect, it } from "vitest";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import {
  BANKS_SORTABLE_COLUMNS,
  DEFAULT_SORT,
  EMPTY_FILTER,
  applyFilters,
  applySort,
  countPanelFilters,
  hasActivePanelFilter,
  isDefaultSort,
  type BanksFilter,
} from "@/app/backoffice/banks/_lib/banksFilterSort";

function bank(
  overrides: Partial<{
    id: string;
    name: string;
    shortName: string;
    createdAt: string;
    updatedAt: string;
  }>,
): Bank {
  return Bank.fromPrimitives({
    id: overrides.id ?? "00000000-0000-0000-0000-000000000000",
    name: overrides.name ?? "Acme Savings",
    shortName: overrides.shortName ?? "ACME",
    createdAt: overrides.createdAt ?? "2026-01-01T10:00:00Z",
    updatedAt: overrides.updatedAt ?? "2026-04-15T14:30:00Z",
  });
}

const acme = bank({
  id: "a",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-15T10:00:00Z",
});
const brookline = bank({
  id: "b",
  name: "Brookline Trust",
  shortName: "BRT",
  createdAt: "2026-02-12T09:00:00Z",
});
const cosmos = bank({
  id: "c",
  name: "Cosmos Bank",
  shortName: "COSM",
  createdAt: "2026-03-20T08:00:00Z",
});
const broken = bank({ id: "d", name: "Wrong Date", shortName: "WD", createdAt: "not-a-date" });

const ROWS: Bank[] = [acme, brookline, cosmos];

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
  });
});

describe("hasActivePanelFilter", () => {
  it("is false when only the toolbar search (name) is set", () => {
    expect(hasActivePanelFilter({ ...EMPTY_FILTER, name: "acme" })).toBe(false);
    expect(hasActivePanelFilter({ ...EMPTY_FILTER, createdFrom: "2026-01-01" })).toBe(true);
  });
});

describe("applyFilters", () => {
  it("matches name case-insensitively", () => {
    const filter: BanksFilter = { ...EMPTY_FILTER, name: "bbva" };
    const matchesBbva = bank({ id: "bbva", name: "BBVA España", shortName: "BBV" });
    const result = applyFilters([acme, matchesBbva, brookline], filter);
    expect(result).toEqual([matchesBbva]);
  });

  it("matches shortName case-insensitively", () => {
    const filter: BanksFilter = { ...EMPTY_FILTER, shortName: "acme" };
    expect(applyFilters(ROWS, filter)).toEqual([acme]);
  });

  it("AND-combines name and shortName", () => {
    const filter: BanksFilter = { ...EMPTY_FILTER, name: "bank", shortName: "co" };
    expect(applyFilters(ROWS, filter)).toEqual([cosmos]);
  });

  it("includes rows on the inclusive lower createdAt bound (yyyy-mm-dd)", () => {
    const filter: BanksFilter = { ...EMPTY_FILTER, createdFrom: "2026-01-15" };
    const result = applyFilters(ROWS, filter);
    expect(result.map((b) => b.id)).toEqual(["a", "b", "c"]);
  });

  it("includes rows on the inclusive upper createdAt bound at end of day (yyyy-mm-dd)", () => {
    const filter: BanksFilter = { ...EMPTY_FILTER, createdTo: "2026-02-12" };
    const result = applyFilters(ROWS, filter);
    expect(result.map((b) => b.id)).toEqual(["a", "b"]);
  });

  it("supports a closed createdAt range in yyyy-mm-dd", () => {
    const filter: BanksFilter = {
      ...EMPTY_FILTER,
      createdFrom: "2026-01-20",
      createdTo: "2026-03-01",
    };
    expect(applyFilters(ROWS, filter).map((b) => b.id)).toEqual(["b"]);
  });

  it("yields no matches when from > to", () => {
    const filter: BanksFilter = {
      ...EMPTY_FILTER,
      createdFrom: "2026-12-01",
      createdTo: "2026-01-01",
    };
    expect(applyFilters(ROWS, filter)).toEqual([]);
  });

  it("excludes rows with invalid createdAt when a range is active", () => {
    const filter: BanksFilter = { ...EMPTY_FILTER, createdFrom: "2026-01-01" };
    expect(applyFilters([acme, broken], filter)).toEqual([acme]);
  });

  it("ignores incomplete yyyy-mm-dd values until parseable", () => {
    const filter: BanksFilter = { ...EMPTY_FILTER, createdFrom: "2026-02" };
    expect(applyFilters(ROWS, filter)).toEqual(ROWS);
  });

  it("rejects impossible calendar dates (2026-02-31)", () => {
    const filter: BanksFilter = { ...EMPTY_FILTER, createdFrom: "2026-02-31" };
    expect(applyFilters(ROWS, filter)).toEqual(ROWS);
  });

  it("keeps rows with invalid createdAt when no range is active", () => {
    expect(applyFilters([acme, broken], EMPTY_FILTER)).toEqual([acme, broken]);
  });

  it("returns the full list when filter is empty", () => {
    expect(applyFilters(ROWS, EMPTY_FILTER)).toEqual(ROWS);
  });

  it("does not mutate the input array", () => {
    const input = [...ROWS];
    applyFilters(input, { ...EMPTY_FILTER, name: "acme" });
    expect(input).toEqual(ROWS);
  });
});

describe("applySort", () => {
  it("returns a fresh array when sort is null", () => {
    const result = applySort(ROWS, null);
    expect(result).toEqual(ROWS);
    expect(result).not.toBe(ROWS);
  });

  it("sorts by name asc with locale-insensitive collation", () => {
    const upper = bank({ id: "u", name: "ZARA Banking", shortName: "ZRA" });
    const lower = bank({ id: "l", name: "alpha bank", shortName: "ALP" });
    const result = applySort([upper, brookline, lower], { columnId: "name", direction: "asc" });
    expect(result.map((b) => b.id)).toEqual(["l", "b", "u"]);
  });

  it("sorts by name desc", () => {
    const result = applySort(ROWS, { columnId: "name", direction: "desc" });
    expect(result.map((b) => b.id)).toEqual(["c", "b", "a"]);
  });

  it("sorts by shortName asc", () => {
    const result = applySort(ROWS, { columnId: "shortName", direction: "asc" });
    expect(result.map((b) => b.id)).toEqual(["a", "b", "c"]);
  });

  it("sorts by createdAt asc / desc", () => {
    expect(applySort(ROWS, { columnId: "createdAt", direction: "asc" }).map((b) => b.id)).toEqual([
      "a",
      "b",
      "c",
    ]);
    expect(applySort(ROWS, { columnId: "createdAt", direction: "desc" }).map((b) => b.id)).toEqual([
      "c",
      "b",
      "a",
    ]);
  });

  it("sorts rows with invalid createdAt to the end (asc) and start (desc)", () => {
    const asc = applySort([acme, broken, brookline], { columnId: "createdAt", direction: "asc" });
    expect(asc.map((b) => b.id)).toEqual(["a", "b", "d"]);
    const desc = applySort([acme, broken, brookline], { columnId: "createdAt", direction: "desc" });
    expect(desc.map((b) => b.id)).toEqual(["d", "b", "a"]);
  });

  it("sorts by updatedAt asc / desc", () => {
    const u1 = bank({ id: "u1", updatedAt: "2026-01-01T00:00:00Z" });
    const u2 = bank({ id: "u2", updatedAt: "2026-04-01T00:00:00Z" });
    const u3 = bank({ id: "u3", updatedAt: "2026-08-01T00:00:00Z" });
    expect(
      applySort([u3, u1, u2], { columnId: "updatedAt", direction: "asc" }).map((b) => b.id),
    ).toEqual(["u1", "u2", "u3"]);
    expect(
      applySort([u1, u3, u2], { columnId: "updatedAt", direction: "desc" }).map((b) => b.id),
    ).toEqual(["u3", "u2", "u1"]);
  });

  it("ignores unknown columnIds and returns input order", () => {
    const result = applySort(ROWS, { columnId: "logo", direction: "asc" });
    expect(result).toEqual(ROWS);
  });

  it("does not mutate the input array", () => {
    const input = [...ROWS];
    applySort(input, { columnId: "name", direction: "desc" });
    expect(input).toEqual(ROWS);
  });
});
