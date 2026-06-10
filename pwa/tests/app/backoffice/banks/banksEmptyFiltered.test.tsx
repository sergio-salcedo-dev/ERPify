import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BanksEmptyFiltered } from "@/app/backoffice/banks/_components/BanksEmptyFiltered";

describe("BanksEmptyFiltered", () => {
  it("renders the distinct dashed-border empty state and wires Reset", () => {
    const onReset = vi.fn();
    render(<BanksEmptyFiltered onReset={onReset} />);

    const section = screen.getByTestId("banks-list__empty-filtered");
    expect(section).toHaveClass("border-dashed");
    expect(screen.getByTestId("banks-list__empty-filtered-heading")).toHaveTextContent(
      "No banks match your filters",
    );
    expect(screen.getByTestId("banks-list__empty-filtered-description")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /clear all/i }));
    expect(onReset).toHaveBeenCalledTimes(1);
  });
});
