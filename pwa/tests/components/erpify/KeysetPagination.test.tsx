import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { KeysetPagination } from "@/components/erpify";

const LABELS = {
  nav: "Test pagination",
  pageSize: "Items per page",
  prev: { text: "Prev", label: "Previous page" },
  next: { text: "Next", label: "Next page" },
} as const;

function renderPagination(overrides: Partial<Parameters<typeof KeysetPagination>[0]> = {}) {
  const props = {
    testId: "x-pagination",
    labels: LABELS,
    pageSize: 25,
    pageSizeOptions: [25, 50, 100] as const,
    hasPrev: true,
    hasNext: true,
    onPrev: vi.fn(),
    onNext: vi.fn(),
    onPageSizeChange: vi.fn(),
    ...overrides,
  };
  render(<KeysetPagination {...props} />);
  return props;
}

describe("KeysetPagination (cursor-only, D-A11y)", () => {
  it("renders prev/next PERSISTENTLY and disables — never hides — a control with no target", () => {
    renderPagination({ hasPrev: false, hasNext: true });

    // Both controls stay in the DOM; the one with no target is disabled, not
    // removed (the sealed D-A11y exception to the hide rule).
    expect(screen.getByRole("button", { name: "Previous page" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Next page" })).toBeEnabled();
    // Cursor-only navigation: just the prev/next pair, no numbered page controls.
    expect(screen.getAllByRole("button")).toHaveLength(2);
    expect(screen.queryByRole("spinbutton")).toBeNull();
  });

  it("fires onPrev/onNext only for enabled controls", () => {
    const props = renderPagination({ hasPrev: true, hasNext: false });

    fireEvent.click(screen.getByRole("button", { name: "Previous page" }));
    fireEvent.click(screen.getByRole("button", { name: "Next page" })); // disabled — no-op

    expect(props.onPrev).toHaveBeenCalledTimes(1);
    expect(props.onNext).not.toHaveBeenCalled();
  });

  it("offers the supplied page sizes and reports a chosen size as a number", () => {
    const props = renderPagination();

    const options = screen
      .getAllByRole("option")
      .map((option) => Number((option as HTMLOptionElement).value));
    expect(options).toEqual([25, 50, 100]);

    fireEvent.change(screen.getByTestId("x-pagination__page-size"), {
      target: { value: "50" },
    });
    expect(props.onPageSizeChange).toHaveBeenCalledWith(50);
  });

  it("ignores a change carrying a size that is not one of the supplied options", () => {
    const props = renderPagination();

    fireEvent.change(screen.getByTestId("x-pagination__page-size"), {
      target: { value: "7" },
    });
    expect(props.onPageSizeChange).not.toHaveBeenCalled();
  });

  it("derives every data-testid and the nav landmark name from its props", () => {
    renderPagination();

    expect(screen.getByTestId("x-pagination")).toHaveAccessibleName("Test pagination");
    expect(screen.getByTestId("x-pagination__prev")).toBeInTheDocument();
    expect(screen.getByTestId("x-pagination__next")).toBeInTheDocument();
    expect(screen.getByTestId("x-pagination__page-size")).toBeInTheDocument();
  });
});
