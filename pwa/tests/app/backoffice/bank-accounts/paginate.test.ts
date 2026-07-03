import { describe, expect, it } from "vitest";
import { WIRE_MAX_LIMIT } from "@/context/shared/search/domain";
import {
  BANK_ACCOUNTS_PAGE_SIZE_OPTIONS,
  isBankAccountsPageSize,
} from "@/app/backoffice/bank-accounts/_lib/paginate";

describe("isBankAccountsPageSize", () => {
  it("accepts every supported page-size option", () => {
    for (const size of BANK_ACCOUNTS_PAGE_SIZE_OPTIONS) {
      expect(isBankAccountsPageSize(size)).toBe(true);
    }
  });

  it("rejects sizes outside the supported set", () => {
    for (const size of [0, 10, 24, 26, 75, 200, 1000]) {
      expect(isBankAccountsPageSize(size)).toBe(false);
    }
  });

  it("never offers an option above the wire ceiling (D-Cap)", () => {
    for (const size of BANK_ACCOUNTS_PAGE_SIZE_OPTIONS) {
      expect(size).toBeLessThanOrEqual(WIRE_MAX_LIMIT);
    }
  });
});
