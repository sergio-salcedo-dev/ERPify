import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BankAccountsPagination } from "@/app/backoffice/bank-accounts/_components/BankAccountsPagination";

describe("BankAccountsPagination (cursor-only, D-A11y)", () => {
  it("renders prev/next persistently and disables — never hides — a control with no target", () => {
    render(
      <BankAccountsPagination
        pageSize={25}
        hasPrev={false}
        hasNext={true}
        onPrev={vi.fn()}
        onNext={vi.fn()}
        onPageSizeChange={vi.fn()}
      />,
    );

    expect(screen.getByRole("button", { name: "Previous page" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Next page" })).toBeEnabled();
    expect(screen.queryByText(/^Page \d/)).toBeNull();
  });

  it("fires onPrev/onNext only for enabled controls", () => {
    const onPrev = vi.fn();
    const onNext = vi.fn();
    render(
      <BankAccountsPagination
        pageSize={25}
        hasPrev={true}
        hasNext={false}
        onPrev={onPrev}
        onNext={onNext}
        onPageSizeChange={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Previous page" }));
    fireEvent.click(screen.getByRole("button", { name: "Next page" })); // disabled — no-op

    expect(onPrev).toHaveBeenCalledTimes(1);
    expect(onNext).not.toHaveBeenCalled();
  });

  it("offers only page sizes within the wire ceiling (D-Cap)", () => {
    render(
      <BankAccountsPagination
        pageSize={25}
        hasPrev={false}
        hasNext={false}
        onPrev={vi.fn()}
        onNext={vi.fn()}
        onPageSizeChange={vi.fn()}
      />,
    );

    const options = screen
      .getAllByRole("option")
      .map((option) => Number((option as HTMLOptionElement).value));
    expect(options).toEqual([25, 50, 100]);
    expect(Math.max(...options)).toBeLessThanOrEqual(100);
  });
});
