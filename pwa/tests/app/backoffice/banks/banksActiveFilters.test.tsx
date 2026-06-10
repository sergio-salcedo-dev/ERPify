import { expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BanksActiveFilters } from "@/app/backoffice/banks/_components/BanksActiveFilters";

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

it("moves focus to the next chip after a non-last chip is removed", () => {
  const { rerender } = render(
    <BanksActiveFilters chips={chips} onRemove={vi.fn()} onClearAll={vi.fn()} />,
  );
  fireEvent.click(screen.getByTestId("banks-filters__chip-name")); // remove index 0
  // Parent re-renders with the remaining chip now at index 0.
  rerender(
    <BanksActiveFilters
      chips={[{ key: "shortName", label: "Code: ACM" }]}
      onRemove={vi.fn()}
      onClearAll={vi.fn()}
    />,
  );
  expect(screen.getByTestId("banks-filters__chip-shortName")).toHaveFocus();
});

it("moves focus to Clear all after the last chip is removed", () => {
  const { rerender } = render(
    <BanksActiveFilters chips={chips} onRemove={vi.fn()} onClearAll={vi.fn()} />,
  );
  fireEvent.click(screen.getByTestId("banks-filters__chip-shortName")); // remove index 1 (last)
  rerender(
    <BanksActiveFilters
      chips={[{ key: "name", label: "Name: Acme" }]}
      onRemove={vi.fn()}
      onClearAll={vi.fn()}
    />,
  );
  expect(screen.getByTestId("banks-filters__clear-all")).toHaveFocus();
});
