import { describe, expect, it } from "vitest";
import {
  BANKS_PAGE_SIZE_DEFAULT,
  BANKS_PAGE_SIZE_OPTIONS,
  paginate,
} from "@/app/backoffice/banks/_lib/paginate";

const range = (n: number): number[] => Array.from({ length: n }, (_, i) => i + 1);

describe("BANKS_PAGE_SIZE_OPTIONS", () => {
  it("offers 25, 50, 100, 500, 1000 in that order", () => {
    expect(BANKS_PAGE_SIZE_OPTIONS).toEqual([25, 50, 100, 500, 1000]);
  });

  it("defaults to 25", () => {
    expect(BANKS_PAGE_SIZE_DEFAULT).toBe(25);
    expect(BANKS_PAGE_SIZE_OPTIONS).toContain(BANKS_PAGE_SIZE_DEFAULT);
  });
});

describe("paginate", () => {
  it("returns the first 25 of 60 on page 1 with hasNext only", () => {
    const out = paginate(range(60), 1, 25);
    expect(out.rows).toEqual(range(25));
    expect(out.page).toBe(1);
    expect(out.hasPrev).toBe(false);
    expect(out.hasNext).toBe(true);
  });

  it("returns rows 26–50 on page 2 with hasPrev and hasNext both true", () => {
    const out = paginate(range(60), 2, 25);
    expect(out.rows).toEqual(range(50).slice(25));
    expect(out.page).toBe(2);
    expect(out.hasPrev).toBe(true);
    expect(out.hasNext).toBe(true);
  });

  it("returns the partial last page with hasNext false", () => {
    const out = paginate(range(60), 3, 25);
    expect(out.rows).toEqual(range(60).slice(50));
    expect(out.page).toBe(3);
    expect(out.hasPrev).toBe(true);
    expect(out.hasNext).toBe(false);
  });

  it("clamps NaN page to 1", () => {
    const out = paginate(range(30), Number.NaN, 25);
    expect(out.page).toBe(1);
    expect(out.rows).toEqual(range(25));
  });

  it("treats non-finite or non-positive pageSize as 1", () => {
    expect(paginate(range(5), 1, 0).rows).toEqual([1]);
    expect(paginate(range(5), 1, 0).hasNext).toBe(true);
    expect(paginate(range(5), 1, -10).rows).toEqual([1]);
    expect(paginate(range(5), 1, Number.POSITIVE_INFINITY).rows).toEqual([1]);
    expect(paginate(range(5), 1, Number.NaN).rows).toEqual([1]);
  });

  it("clamps page above the last page", () => {
    const out = paginate(range(30), 99, 25);
    expect(out.page).toBe(2);
    expect(out.rows).toEqual(range(30).slice(25));
    expect(out.hasNext).toBe(false);
  });

  it("clamps page below 1 to 1", () => {
    const out = paginate(range(30), 0, 25);
    expect(out.page).toBe(1);
    expect(out.hasPrev).toBe(false);
  });

  it("clamps negative page to 1", () => {
    const out = paginate(range(30), -3, 25);
    expect(out.page).toBe(1);
  });

  it("returns empty rows and no nav for an empty list", () => {
    const out = paginate<number>([], 1, 25);
    expect(out.rows).toEqual([]);
    expect(out.hasPrev).toBe(false);
    expect(out.hasNext).toBe(false);
    expect(out.page).toBe(1);
  });

  it("does not advertise more pages when items <= pageSize", () => {
    expect(paginate(range(25), 1, 25).hasNext).toBe(false);
    expect(paginate(range(1), 1, 25).hasNext).toBe(false);
  });

  it("does not mutate the input array", () => {
    const input = range(60);
    const snapshot = [...input];
    paginate(input, 2, 25);
    expect(input).toEqual(snapshot);
  });

  it("supports the larger page sizes from the options list", () => {
    const out = paginate(range(150), 2, 100);
    expect(out.rows).toEqual(range(150).slice(100));
    expect(out.hasPrev).toBe(true);
    expect(out.hasNext).toBe(false);
  });

  it("works with object items", () => {
    const items = range(30).map((id) => ({ id }));
    const out = paginate(items, 2, 25);
    expect(out.rows).toEqual([{ id: 26 }, { id: 27 }, { id: 28 }, { id: 29 }, { id: 30 }]);
  });
});
