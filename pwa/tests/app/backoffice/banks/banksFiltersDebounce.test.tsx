import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { BanksFilters } from "@/app/backoffice/banks/_components/BanksFilters";
import { DEFAULT_SORT, EMPTY_FILTER } from "@/app/backoffice/banks/_lib/banksFilterSort";

describe("BanksFilters — debounce", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("syncs the inputs down when the parent changes the filter (e.g. Reset)", () => {
    const onFilterChange = vi.fn();
    const seeded = { ...EMPTY_FILTER, name: "acme" };
    const { rerender } = render(
      <BanksFilters
        filter={seeded}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={vi.fn()}
        defaultOpen
      />,
    );

    const input = screen.getByTestId("banks-filters__name") as HTMLInputElement;
    expect(input.value).toBe("acme");

    onFilterChange.mockClear();

    // Parent resets the filter externally.
    rerender(
      <BanksFilters
        filter={EMPTY_FILTER}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={vi.fn()}
        defaultOpen
      />,
    );

    expect((screen.getByTestId("banks-filters__name") as HTMLInputElement).value).toBe("");
    // The render-phase sync must not itself push a change back up.
    act(() => {
      vi.advanceTimersByTime(300);
    });
    expect(onFilterChange).not.toHaveBeenCalled();
  });

  it("debounces name filter changes (~300ms) and emits the latest value once", () => {
    const onFilterChange = vi.fn();
    render(
      <BanksFilters
        filter={EMPTY_FILTER}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={vi.fn()}
        defaultOpen
      />,
    );

    const input = screen.getByTestId("banks-filters__name");
    fireEvent.change(input, { target: { value: "ac" } });
    fireEvent.change(input, { target: { value: "acme" } });

    expect(onFilterChange).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(300);
    });

    expect(onFilterChange).toHaveBeenCalledTimes(1);
    expect(onFilterChange).toHaveBeenCalledWith(expect.objectContaining({ name: "acme" }));
  });
});
