import { describe, expect, it, vi, type Mock } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BankAccountsFilters } from "@/app/backoffice/bank-accounts/_components/BankAccountsFilters";
import {
  DEFAULT_SORT,
  EMPTY_FILTER,
  type BankAccountsFilter,
  type BankAccountsSort,
} from "@/app/backoffice/bank-accounts/_lib/bankAccountsFilterSort";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";

const ACTIVE_FILTER: BankAccountsFilter = { holderName: "Alice", alias: "Payroll", bank: "Acme" };
const DRIFTED_SORT: BankAccountsSort = { columnId: "createdAt", direction: SortDirection.DESC };

function renderFilters(
  overrides: {
    onFilterChange?: Mock<(next: BankAccountsFilter) => void>;
    onSortChange?: Mock<(next: BankAccountsSort) => void>;
    onReset?: Mock<() => void>;
    filter?: BankAccountsFilter;
    sort?: BankAccountsSort;
  } = {},
) {
  const props = {
    onFilterChange: overrides.onFilterChange ?? vi.fn<(next: BankAccountsFilter) => void>(),
    onSortChange: overrides.onSortChange ?? vi.fn<(next: BankAccountsSort) => void>(),
    onReset: overrides.onReset ?? vi.fn<() => void>(),
  };
  render(
    <BankAccountsFilters
      filter={overrides.filter ?? ACTIVE_FILTER}
      sort={overrides.sort === undefined ? DRIFTED_SORT : overrides.sort}
      onFilterChange={props.onFilterChange}
      onSortChange={props.onSortChange}
      onReset={props.onReset}
      defaultOpen
    />,
  );
  return props;
}

describe("BankAccountsFilters — chip removal", () => {
  it("clears the holder search when its chip is removed, leaving sort untouched", () => {
    const { onFilterChange, onSortChange } = renderFilters();

    fireEvent.click(screen.getByTestId("bank-accounts-filters__chip-holderName"));

    expect(onFilterChange).toHaveBeenCalledWith({ ...ACTIVE_FILTER, holderName: "" });
    expect(onSortChange).not.toHaveBeenCalled();
  });

  it("clears the alias filter when its chip is removed", () => {
    const { onFilterChange } = renderFilters();

    fireEvent.click(screen.getByTestId("bank-accounts-filters__chip-alias"));

    expect(onFilterChange).toHaveBeenCalledWith({ ...ACTIVE_FILTER, alias: "" });
  });

  it("clears the bank filter when its chip is removed", () => {
    const { onFilterChange } = renderFilters();

    fireEvent.click(screen.getByTestId("bank-accounts-filters__chip-bank"));

    expect(onFilterChange).toHaveBeenCalledWith({ ...ACTIVE_FILTER, bank: "" });
  });

  it("restores the default sort when the sort chip is removed, leaving filters untouched", () => {
    const { onFilterChange, onSortChange } = renderFilters();

    fireEvent.click(screen.getByTestId("bank-accounts-filters__chip-sort"));

    expect(onSortChange).toHaveBeenCalledWith(DEFAULT_SORT);
    expect(onFilterChange).not.toHaveBeenCalled();
  });

  it("resets everything when Clear all is pressed", () => {
    const { onReset } = renderFilters();

    fireEvent.click(screen.getByTestId("bank-accounts-filters__clear-all"));

    expect(onReset).toHaveBeenCalledTimes(1);
  });
});

describe("BankAccountsFilters — sort controls", () => {
  it("emits a null (Unsorted) sort when 'None' is chosen", () => {
    const { onSortChange } = renderFilters();

    fireEvent.change(screen.getByTestId("bank-accounts-filters__sort-by"), {
      target: { value: "__none__" },
    });

    expect(onSortChange).toHaveBeenCalledWith(null);
  });

  it("keeps the current direction when only the sort column changes", () => {
    const { onSortChange } = renderFilters();

    fireEvent.change(screen.getByTestId("bank-accounts-filters__sort-by"), {
      target: { value: "updatedAt" },
    });

    expect(onSortChange).toHaveBeenCalledWith({
      columnId: "updatedAt",
      direction: SortDirection.DESC,
    });
  });

  it("changes only the direction when the direction select changes", () => {
    const { onSortChange } = renderFilters();

    fireEvent.change(screen.getByTestId("bank-accounts-filters__sort-direction"), {
      target: { value: SortDirection.ASC },
    });

    expect(onSortChange).toHaveBeenCalledWith({ ...DRIFTED_SORT, direction: SortDirection.ASC });
  });

  it("disables the direction select while the list is Unsorted (null sort)", () => {
    renderFilters({ filter: EMPTY_FILTER, sort: null });

    expect(screen.getByTestId("bank-accounts-filters__sort-direction")).toBeDisabled();
  });
});
