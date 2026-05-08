import { describe, expect, it } from "vitest";
import { BANKS_PAGE_SIZE, paginate } from "@/app/backoffice/banks/_lib/paginate";

const range = (n: number): number[] => Array.from({ length: n }, (_, i) => i + 1);

describe("BANKS_PAGE_SIZE", () => {
  it("is 10", () => {
    expect(BANKS_PAGE_SIZE).toBe(10);
  });
});

describe("paginate", () => {
  it("returns the first 10 of 50 on page 1", () => {
    const out = paginate(range(50), 1, 10);
    expect(out.rows).toEqual(range(10));
    expect(out.page).toBe(1);
    expect(out.totalPages).toBe(5);
    expect(out.totalRows).toBe(50);
  });

  it("returns rows 21–30 on page 3", () => {
    const out = paginate(range(50), 3, 10);
    expect(out.rows).toEqual([21, 22, 23, 24, 25, 26, 27, 28, 29, 30]);
    expect(out.page).toBe(3);
  });

  it("returns the partial last page", () => {
    const out = paginate(range(47), 5, 10);
    expect(out.rows).toEqual([41, 42, 43, 44, 45, 46, 47]);
    expect(out.page).toBe(5);
    expect(out.totalPages).toBe(5);
    expect(out.totalRows).toBe(47);
  });

  it("clamps NaN page to 1", () => {
    const out = paginate(range(15), Number.NaN, 10);
    expect(out.page).toBe(1);
    expect(out.rows).toEqual(range(10));
  });

  it("treats non-finite or non-positive pageSize as 1", () => {
    expect(paginate(range(5), 1, 0).rows).toEqual([1]);
    expect(paginate(range(5), 1, 0).totalPages).toBe(5);
    expect(paginate(range(5), 1, -10).rows).toEqual([1]);
    expect(paginate(range(5), 1, Number.POSITIVE_INFINITY).rows).toEqual([1]);
    expect(paginate(range(5), 1, Number.NaN).rows).toEqual([1]);
  });

  it("clamps page above totalPages to the last page", () => {
    const out = paginate(range(15), 99, 10);
    expect(out.page).toBe(2);
    expect(out.rows).toEqual([11, 12, 13, 14, 15]);
  });

  it("clamps page below 1 to 1", () => {
    const out = paginate(range(15), 0, 10);
    expect(out.page).toBe(1);
    expect(out.rows).toEqual(range(10));
  });

  it("clamps negative page to 1", () => {
    const out = paginate(range(15), -3, 10);
    expect(out.page).toBe(1);
  });

  it("returns totalPages=1 and empty rows for an empty list", () => {
    const out = paginate<number>([], 1, 10);
    expect(out.rows).toEqual([]);
    expect(out.totalPages).toBe(1);
    expect(out.totalRows).toBe(0);
    expect(out.page).toBe(1);
  });

  it("hides pagination semantics: totalPages stays 1 when items <= pageSize", () => {
    expect(paginate(range(10), 1, 10).totalPages).toBe(1);
    expect(paginate(range(1), 1, 10).totalPages).toBe(1);
  });

  it("does not mutate the input array", () => {
    const input = range(25);
    const snapshot = [...input];
    paginate(input, 2, 10);
    expect(input).toEqual(snapshot);
  });

  it("supports a non-default page size", () => {
    const out = paginate(range(25), 2, 5);
    expect(out.rows).toEqual([6, 7, 8, 9, 10]);
    expect(out.totalPages).toBe(5);
  });

  it("works with object items", () => {
    const items = range(12).map((id) => ({ id }));
    const out = paginate(items, 2, 10);
    expect(out.rows).toEqual([{ id: 11 }, { id: 12 }]);
  });
});
