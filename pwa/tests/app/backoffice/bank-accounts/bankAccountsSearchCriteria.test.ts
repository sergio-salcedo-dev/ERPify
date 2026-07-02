import { describe, expect, it } from "vitest";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import {
  toBankAccountFilters,
  toBankAccountSort,
} from "@/app/backoffice/bank-accounts/_lib/bankAccountsSearchCriteria";
import { EMPTY_FILTER } from "@/app/backoffice/bank-accounts/_lib/bankAccountsFilterSort";

describe("bank-accounts search criteria", () => {
  it("maps holder/alias to `contains` and bankId to `eq`", () => {
    const filters = toBankAccountFilters({
      holderName: "Alice",
      alias: "Payroll",
      bankId: "11111111-1111-4111-8111-111111111111",
    });

    expect(filters).toEqual([
      { field: "holderName", operator: "contains", value: "Alice" },
      { field: "alias", operator: "contains", value: "Payroll" },
      { field: "bankId", operator: "eq", value: "11111111-1111-4111-8111-111111111111" },
    ]);
  });

  it("omits blank and whitespace-only fields", () => {
    expect(toBankAccountFilters(EMPTY_FILTER)).toEqual([]);
    expect(toBankAccountFilters({ holderName: "  ", alias: "   ", bankId: "" })).toEqual([]);
  });

  it("trims values before emitting them", () => {
    expect(toBankAccountFilters({ ...EMPTY_FILTER, holderName: "  Bob  " })).toEqual([
      { field: "holderName", operator: "contains", value: "Bob" },
    ]);
  });

  it("omits a bankId that is not yet a complete UUID (a partial value must not error the list)", () => {
    expect(toBankAccountFilters({ ...EMPTY_FILTER, bankId: "1111" })).toEqual([]);
    expect(toBankAccountFilters({ ...EMPTY_FILTER, bankId: "not-a-uuid" })).toEqual([]);
  });

  it("maps the UI sort to the domain sort, or null", () => {
    expect(toBankAccountSort({ columnId: "holderName", direction: SortDirection.DESC })).toEqual({
      field: "holderName",
      direction: SortDirection.DESC,
    });
    expect(toBankAccountSort(null)).toBeNull();
  });
});
