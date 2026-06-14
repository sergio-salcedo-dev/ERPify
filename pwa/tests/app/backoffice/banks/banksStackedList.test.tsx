import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BanksStackedList } from "@/app/backoffice/banks/_components/BanksStackedList";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: (...args: unknown[]) => push(...args), refresh: vi.fn() }),
}));

const BANKS = [
  Bank.fromPrimitives({
    id: "11111111-1111-4111-8111-111111111111",
    name: "Banco Uno",
    shortName: "UNO",
    createdAt: "2026-01-01T10:00:00Z",
    updatedAt: "2026-01-01T10:00:00Z",
  }),
  Bank.fromPrimitives({
    id: "22222222-2222-4222-8222-222222222222",
    name: "Banco Dos",
    shortName: "DOS",
    createdAt: "2026-01-01T10:00:00Z",
    updatedAt: "2026-01-01T10:00:00Z",
  }),
];

describe("BanksStackedList — mobile keyboard contract", () => {
  it("each row-card is a single roving tab stop in visual order", () => {
    render(<BanksStackedList onBankDeleteFailed={() => {}} banks={BANKS} />);
    const first = screen.getByTestId(`banks-stacked__row-${BANKS[0].id}`);
    const second = screen.getByTestId(`banks-stacked__row-${BANKS[1].id}`);
    expect(first).toHaveAttribute("tabindex", "0");
    expect(second).toHaveAttribute("tabindex", "-1");
  });

  it("ArrowDown moves focus to the next row; the row link opens the detail", () => {
    render(<BanksStackedList onBankDeleteFailed={() => {}} banks={BANKS} />);
    const first = screen.getByTestId(`banks-stacked__row-${BANKS[0].id}`);
    const second = screen.getByTestId(`banks-stacked__row-${BANKS[1].id}`);

    first.focus();
    fireEvent.keyDown(first, { key: "ArrowDown" });
    expect(second).toHaveFocus();

    // The roving tab stop is a real link — Enter is native activation.
    expect(second).toHaveAttribute("href", `/backoffice/banks/${BANKS[1].id}`);
  });

  it("keeps a valid roving tab stop when an optimistic delete shrinks the list", () => {
    const { rerender } = render(<BanksStackedList onBankDeleteFailed={() => {}} banks={BANKS} />);
    // Move the active row to the last card, then drop it (optimistic delete).
    const first = screen.getByTestId(`banks-stacked__row-${BANKS[0].id}`);
    fireEvent.focus(first);
    fireEvent.keyDown(first, { key: "ArrowDown" });
    rerender(<BanksStackedList onBankDeleteFailed={() => {}} banks={[BANKS[0]]} />);

    // The clamp keeps the (now only) row as the tab stop instead of leaving the
    // stale index 1 past the end with no focusable row.
    expect(screen.getByTestId(`banks-stacked__row-${BANKS[0].id}`)).toHaveAttribute(
      "tabindex",
      "0",
    );
  });

  it("Space toggles selection and the checkbox is always visible", () => {
    const onToggleSelect = vi.fn();
    render(
      <BanksStackedList
        onBankDeleteFailed={() => {}}
        banks={BANKS}
        selectedIds={new Set()}
        onToggleSelect={onToggleSelect}
      />,
    );
    const first = screen.getByTestId(`banks-stacked__row-${BANKS[0].id}`);
    fireEvent.keyDown(first, { key: " " });
    expect(onToggleSelect).toHaveBeenCalledWith(BANKS[0].id);

    const checkbox = screen.getByTestId(`banks-stacked__select-${BANKS[0].id}`);
    expect(checkbox.className).not.toContain("opacity-0");
  });
});
