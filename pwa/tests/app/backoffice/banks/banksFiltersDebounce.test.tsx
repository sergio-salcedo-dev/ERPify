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

  it("Clear all clears a pending (un-propagated) name input and cancels the debounce", () => {
    const onFilterChange = vi.fn();
    const onReset = vi.fn();
    render(
      <BanksFilters
        filter={{ ...EMPTY_FILTER, shortName: "x" }}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={onReset}
        defaultOpen
      />,
    );

    const nameInput = screen.getByTestId("banks-filters__name") as HTMLInputElement;
    fireEvent.change(nameInput, { target: { value: "cosmos" } });

    fireEvent.click(screen.getByTestId("banks-filters__clear-all"));
    expect(onReset).toHaveBeenCalledTimes(1);
    expect((screen.getByTestId("banks-filters__name") as HTMLInputElement).value).toBe("");

    act(() => {
      vi.advanceTimersByTime(300);
    });
    expect(onFilterChange).not.toHaveBeenCalledWith(expect.objectContaining({ name: "cosmos" }));
  });

  it("removing the code chip clears shortName without re-applying a pending value", () => {
    const onFilterChange = vi.fn();
    render(
      <BanksFilters
        filter={{ ...EMPTY_FILTER, shortName: "ACM" }}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={vi.fn()}
        defaultOpen
      />,
    );

    fireEvent.click(screen.getByTestId("banks-filters__chip-shortName"));
    expect(onFilterChange).toHaveBeenCalledWith(expect.objectContaining({ shortName: "" }));
  });

  it("removing the name chip clears name and cancels a pending typed value", () => {
    const onFilterChange = vi.fn();
    render(
      <BanksFilters
        filter={{ ...EMPTY_FILTER, name: "acme" }}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={vi.fn()}
        defaultOpen
      />,
    );

    // Type a newer, still-undebounced value over the committed "acme".
    fireEvent.change(screen.getByTestId("banks-filters__name"), { target: { value: "acme2" } });

    fireEvent.click(screen.getByTestId("banks-filters__chip-name"));
    expect(onFilterChange).toHaveBeenCalledWith(expect.objectContaining({ name: "" }));

    // The cancelled debounce must never re-apply the stale "acme2".
    act(() => {
      vi.advanceTimersByTime(300);
    });
    expect(onFilterChange).not.toHaveBeenCalledWith(expect.objectContaining({ name: "acme2" }));
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
