import { describe, expect, it } from "vitest";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import {
  EMPTY_FILTER,
  applyFilters,
  applySort,
  hasActiveFilter,
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

describe("hasActiveFilter", () => {
  it("returns false on the empty filter", () => {
    expect(hasActiveFilter(EMPTY_FILTER)).toBe(false);
  });

  it("returns true when any field is non-empty", () => {
    expect(hasActiveFilter({ ...EMPTY_FILTER, name: "x" })).toBe(true);
    expect(hasActiveFilter({ ...EMPTY_FILTER, shortName: "x" })).toBe(true);
    expect(hasActiveFilter({ ...EMPTY_FILTER, createdFrom: "2026-01-01" })).toBe(true);
    expect(hasActiveFilter({ ...EMPTY_FILTER, createdTo: "2026-01-01" })).toBe(true);
  });

  it("treats whitespace-only name/shortName as inactive", () => {
    expect(hasActiveFilter({ ...EMPTY_FILTER, name: "   ", shortName: "  " })).toBe(false);
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

  it("includes rows on the inclusive lower createdAt bound", () => {
    const filter: BanksFilter = { ...EMPTY_FILTER, createdFrom: "2026-01-15" };
    const result = applyFilters(ROWS, filter);
    expect(result.map((b) => b.id)).toEqual(["a", "b", "c"]);
  });

  it("includes rows on the inclusive upper createdAt bound (end of day)", () => {
    const filter: BanksFilter = { ...EMPTY_FILTER, createdTo: "2026-02-12" };
    const result = applyFilters(ROWS, filter);
    expect(result.map((b) => b.id)).toEqual(["a", "b"]);
  });

  it("supports a closed createdAt range", () => {
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

  it("ignores unknown columnIds and returns input order", () => {
    const result = applySort(ROWS, { columnId: "updatedAt", direction: "asc" });
    expect(result).toEqual(ROWS);
  });

  it("does not mutate the input array", () => {
    const input = [...ROWS];
    applySort(input, { columnId: "name", direction: "desc" });
    expect(input).toEqual(ROWS);
  });
});
