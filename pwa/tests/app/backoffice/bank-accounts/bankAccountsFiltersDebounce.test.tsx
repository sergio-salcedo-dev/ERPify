import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { BankAccountsFilters } from "@/app/backoffice/bank-accounts/_components/BankAccountsFilters";
import {
  DEFAULT_SORT,
  EMPTY_FILTER,
} from "@/app/backoffice/bank-accounts/_lib/bankAccountsFilterSort";

describe("BankAccountsFilters — debounce", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("debounces holder search changes (~300ms) and emits the latest value once", () => {
    const onFilterChange = vi.fn();
    render(
      <BankAccountsFilters
        filter={EMPTY_FILTER}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={vi.fn()}
        defaultOpen
      />,
    );

    const input = screen.getByTestId("bank-accounts-filters__holder");
    fireEvent.change(input, { target: { value: "al" } });
    fireEvent.change(input, { target: { value: "alice" } });

    expect(onFilterChange).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(300);
    });

    expect(onFilterChange).toHaveBeenCalledTimes(1);
    expect(onFilterChange).toHaveBeenCalledWith(expect.objectContaining({ holderName: "alice" }));
  });

  it("debounces the panel alias filter as a `contains` value", () => {
    const onFilterChange = vi.fn();
    render(
      <BankAccountsFilters
        filter={EMPTY_FILTER}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={vi.fn()}
        defaultOpen
      />,
    );

    fireEvent.change(screen.getByTestId("bank-accounts-filters__alias"), {
      target: { value: "Payroll" },
    });

    act(() => {
      vi.advanceTimersByTime(300);
    });

    expect(onFilterChange).toHaveBeenCalledWith(expect.objectContaining({ alias: "Payroll" }));
  });

  it("debounces the panel bank filter as a `contains` value (name or short code)", () => {
    const onFilterChange = vi.fn();
    render(
      <BankAccountsFilters
        filter={EMPTY_FILTER}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={vi.fn()}
        defaultOpen
      />,
    );

    fireEvent.change(screen.getByTestId("bank-accounts-filters__bank"), {
      target: { value: "Acme" },
    });

    act(() => {
      vi.advanceTimersByTime(300);
    });

    expect(onFilterChange).toHaveBeenCalledWith(expect.objectContaining({ bank: "Acme" }));
  });

  it("syncs the inputs down when the parent resets the filter", () => {
    const onFilterChange = vi.fn();
    const seeded = { ...EMPTY_FILTER, holderName: "alice" };
    const { rerender } = render(
      <BankAccountsFilters
        filter={seeded}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={vi.fn()}
        defaultOpen
      />,
    );

    expect((screen.getByTestId("bank-accounts-filters__holder") as HTMLInputElement).value).toBe(
      "alice",
    );
    onFilterChange.mockClear();

    rerender(
      <BankAccountsFilters
        filter={EMPTY_FILTER}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={vi.fn()}
        defaultOpen
      />,
    );

    expect((screen.getByTestId("bank-accounts-filters__holder") as HTMLInputElement).value).toBe(
      "",
    );
    // The render-phase sync must not itself push a change back up.
    act(() => {
      vi.advanceTimersByTime(300);
    });
    expect(onFilterChange).not.toHaveBeenCalled();
  });
});
