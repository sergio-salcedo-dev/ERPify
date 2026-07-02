import { describe, expect, it } from "vitest";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import {
  toBankAccountFilters,
  toBankAccountSort,
} from "@/app/backoffice/bank-accounts/_lib/bankAccountsSearchCriteria";
import { EMPTY_FILTER } from "@/app/backoffice/bank-accounts/_lib/bankAccountsFilterSort";

describe("bank-accounts search criteria", () => {
  it("maps holder/alias/bank to `contains`", () => {
    const filters = toBankAccountFilters({
      holderName: "Alice",
      alias: "Payroll",
      bank: "Acme",
    });

    expect(filters).toEqual([
      { field: "holderName", operator: "contains", value: "Alice" },
      { field: "alias", operator: "contains", value: "Payroll" },
      { field: "bank", operator: "contains", value: "Acme" },
    ]);
  });

  it("omits blank and whitespace-only fields", () => {
    expect(toBankAccountFilters(EMPTY_FILTER)).toEqual([]);
    expect(toBankAccountFilters({ holderName: "  ", alias: "   ", bank: "" })).toEqual([]);
  });

  it("trims values before emitting them", () => {
    expect(toBankAccountFilters({ ...EMPTY_FILTER, holderName: "  Bob  " })).toEqual([
      { field: "holderName", operator: "contains", value: "Bob" },
    ]);
  });

  it("emits a partial bank term as `contains` (a substring is valid, unlike an exact id)", () => {
    expect(toBankAccountFilters({ ...EMPTY_FILTER, bank: "Acm" })).toEqual([
      { field: "bank", operator: "contains", value: "Acm" },
    ]);
    expect(toBankAccountFilters({ ...EMPTY_FILTER, bank: "  ACME  " })).toEqual([
      { field: "bank", operator: "contains", value: "ACME" },
    ]);
  });

  it("maps the UI sort to the domain sort, or null", () => {
    expect(toBankAccountSort({ columnId: "holderName", direction: SortDirection.DESC })).toEqual({
      field: "holderName",
      direction: SortDirection.DESC,
    });
    expect(toBankAccountSort(null)).toBeNull();
  });
});
