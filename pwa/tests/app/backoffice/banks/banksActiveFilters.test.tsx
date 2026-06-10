import { expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BanksActiveFilters } from "@/app/backoffice/banks/_components/BanksActiveFilters";
import type { FilterChipDescriptor } from "@/app/backoffice/banks/_lib/banksFilterSort";

const chips = [
  { key: "name" as const, label: "Name: Acme" },
  { key: "shortName" as const, label: "Code: ACM" },
];

it("renders a chip per descriptor and a Clear all button", () => {
  render(<BanksActiveFilters chips={chips} onRemove={vi.fn()} onClearAll={vi.fn()} />);
  expect(screen.getByTestId("banks-filters__active")).toBeInTheDocument();
  expect(screen.getByText("Name: Acme")).toBeInTheDocument();
  expect(screen.getByText("Code: ACM")).toBeInTheDocument();
  expect(screen.getByTestId("banks-filters__clear-all")).toBeInTheDocument();
});

it("calls onRemove with the chip key when its ✕ is clicked", () => {
  const onRemove = vi.fn();
  render(<BanksActiveFilters chips={chips} onRemove={onRemove} onClearAll={vi.fn()} />);
  fireEvent.click(screen.getByTestId("banks-filters__chip-shortName"));
  expect(onRemove).toHaveBeenCalledWith("shortName");
});

it("calls onClearAll when Clear all is clicked", () => {
  const onClearAll = vi.fn();
  render(<BanksActiveFilters chips={chips} onRemove={vi.fn()} onClearAll={onClearAll} />);
  fireEvent.click(screen.getByTestId("banks-filters__clear-all"));
  expect(onClearAll).toHaveBeenCalledTimes(1);
});

// `remaining` mirrors the parent's post-removal chip set, so each assertion
// below exercises the same render → remove → re-render flow with different ends.
function removeChipThenRerender(removeTestId: string, remaining: FilterChipDescriptor) {
  const { rerender } = render(
    <BanksActiveFilters chips={chips} onRemove={vi.fn()} onClearAll={vi.fn()} />,
  );
  fireEvent.click(screen.getByTestId(removeTestId));
  rerender(<BanksActiveFilters chips={[remaining]} onRemove={vi.fn()} onClearAll={vi.fn()} />);
}

it("moves focus to the next chip after a non-last chip is removed", () => {
  removeChipThenRerender("banks-filters__chip-name", { key: "shortName", label: "Code: ACM" });
  expect(screen.getByTestId("banks-filters__chip-shortName")).toHaveFocus();
});

it("moves focus to Clear all after the last chip is removed", () => {
  removeChipThenRerender("banks-filters__chip-shortName", { key: "name", label: "Name: Acme" });
  expect(screen.getByTestId("banks-filters__clear-all")).toHaveFocus();
});
