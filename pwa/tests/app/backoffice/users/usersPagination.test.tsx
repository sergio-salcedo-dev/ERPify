import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { UsersPagination } from "@/app/backoffice/users/_components/UsersPagination";

function renderPagination(overrides: Partial<Parameters<typeof UsersPagination>[0]> = {}) {
  const props = {
    pageSize: 25 as const,
    hasPrev: false,
    hasNext: false,
    onPrev: vi.fn(),
    onNext: vi.fn(),
    onPageSizeChange: vi.fn(),
    ...overrides,
  };
  render(<UsersPagination {...props} />);
  return props;
}

describe("UsersPagination (cursor-only, D-A11y)", () => {
  it("renders prev/next persistently and disables — never hides — a control with no target", () => {
    renderPagination({ hasPrev: false, hasNext: true });

    expect(screen.getByRole("navigation", { name: "Users pagination" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Previous page" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Next page" })).toBeEnabled();
  });

  it("fires onPrev/onNext only for enabled controls", () => {
    const { onPrev, onNext } = renderPagination({ hasPrev: true, hasNext: false });

    fireEvent.click(screen.getByRole("button", { name: "Previous page" }));
    fireEvent.click(screen.getByRole("button", { name: "Next page" }));

    expect(onPrev).toHaveBeenCalledTimes(1);
    expect(onNext).not.toHaveBeenCalled();
  });

  it("offers only page sizes within the wire ceiling", () => {
    renderPagination();

    const options = screen
      .getAllByRole("option")
      .map((option) => Number((option as HTMLOptionElement).value));
    expect(options).toEqual([25, 50, 100]);
  });

  it("propagates an offered page size and drops one that is not offered", () => {
    const { onPageSizeChange } = renderPagination();
    const select = screen.getByTestId("users-pagination__page-size") as HTMLSelectElement;

    fireEvent.change(select, { target: { value: "50" } });
    expect(onPageSizeChange).toHaveBeenCalledExactlyOnceWith(50);

    const foreign = document.createElement("option");
    foreign.value = "500";
    select.appendChild(foreign);
    fireEvent.change(select, { target: { value: "500" } });
    expect(onPageSizeChange).toHaveBeenCalledTimes(1);
  });

  it("keeps the published test ids of the users surface", () => {
    renderPagination();

    for (const id of [
      "users-pagination",
      "users-pagination__page-size",
      "users-pagination__prev",
      "users-pagination__next",
    ]) {
      expect(screen.getByTestId(id)).toBeInTheDocument();
    }
  });
});
