import { describe, expect, it } from "vitest";
import { BANKS_PAGE_SIZE_OPTIONS, isBanksPageSize } from "@/app/backoffice/banks/_lib/paginate";

describe("isBanksPageSize", () => {
  it("accepts every supported page-size option", () => {
    for (const size of BANKS_PAGE_SIZE_OPTIONS) {
      expect(isBanksPageSize(size)).toBe(true);
    }
  });

  it("rejects sizes outside the supported set", () => {
    for (const size of [0, 10, 24, 26, 75, 200, 1000]) {
      expect(isBanksPageSize(size)).toBe(false);
    }
  });
});
