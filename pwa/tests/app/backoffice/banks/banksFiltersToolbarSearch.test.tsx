import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { BanksFilters } from "@/app/backoffice/banks/_components/BanksFilters";
import { DEFAULT_SORT, EMPTY_FILTER } from "@/app/backoffice/banks/_lib/banksFilterSort";

function renderFilters(props: Partial<Parameters<typeof BanksFilters>[0]> = {}) {
  const onFilterChange = vi.fn();
  const utils = render(
    <BanksFilters
      filter={EMPTY_FILTER}
      onFilterChange={onFilterChange}
      sort={DEFAULT_SORT}
      onSortChange={vi.fn()}
      onReset={vi.fn()}
      {...props}
    />,
  );
  return { onFilterChange, ...utils };
}

describe("BanksFilters — toolbar search", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("renders the name search in the toolbar, outside the collapsible panel", () => {
    const { container } = renderFilters();
    const search = screen.getByTestId("banks-filters__name");
    const panel = screen.getByTestId("banks-filters__panel");
    // Panel starts collapsed (no active panel filters) yet the search is live.
    expect(panel).toHaveAttribute("aria-hidden", "true");
    expect(panel.contains(search)).toBe(false);
    expect(search).not.toHaveAttribute("disabled");
    const toolbar = container.querySelector(".banks-filters__toolbar");
    expect(toolbar?.contains(search)).toBe(true);
  });

  it("debounces typed text into filter.name", () => {
    const { onFilterChange } = renderFilters();
    fireEvent.change(screen.getByTestId("banks-filters__name"), {
      target: { value: "acme" },
    });
    expect(onFilterChange).not.toHaveBeenCalled();
    act(() => {
      vi.advanceTimersByTime(300);
    });
    expect(onFilterChange).toHaveBeenCalledWith({ ...EMPTY_FILTER, name: "acme" });
  });

  it("does not count name in the Filters badge and does not auto-open the panel for it", () => {
    renderFilters({ filter: { ...EMPTY_FILTER, name: "acme" } });
    expect(screen.queryByTestId("banks-filters__count")).not.toBeInTheDocument();
    expect(screen.getByTestId("banks-filters__panel")).toHaveAttribute("aria-hidden", "true");
  });

  it("still counts panel fields in the badge and auto-opens for them", () => {
    renderFilters({ filter: { ...EMPTY_FILTER, shortName: "ACM" } });
    expect(screen.getByTestId("banks-filters__count")).toHaveTextContent("1");
    expect(screen.getByTestId("banks-filters__panel")).toHaveAttribute("aria-hidden", "false");
  });
});
