import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { BanksCards } from "@/app/backoffice/banks/_components/BanksCards";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

const RECENT = Bank.fromPrimitives({
  id: "33333333-3333-4333-8333-333333333333",
  name: "Santander Bank",
  shortName: "SANB",
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
  accountCount: 0,
});

const OLD = Bank.fromPrimitives({
  id: "44444444-4444-4444-8444-444444444444",
  name: "Caixa Bank",
  shortName: "CAIX",
  createdAt: "2020-01-01T00:00:00.000Z",
  updatedAt: "2020-01-01T00:00:00.000Z",
  accountCount: 0,
});

describe("BanksCards — identity", () => {
  it("shows a New badge in the status region only for recently created banks", () => {
    render(<BanksCards onBankDeleteFailed={() => {}} banks={[RECENT, OLD]} />);
    expect(screen.getByTestId(`banks-cards__new-${RECENT.id}`)).toHaveTextContent("New");
    expect(screen.queryByTestId(`banks-cards__new-${OLD.id}`)).toBeNull();
    // Non-recent banks read as Active — the status region is never empty.
    expect(screen.getByText("Active")).toBeInTheDocument();
  });

  it("renders updated as relative text with the absolute value in the title; created lives in the detail", () => {
    render(<BanksCards onBankDeleteFailed={() => {}} banks={[OLD]} />);
    const updated = screen.getByTestId(`banks-cards__updated-${OLD.id}`);
    expect(updated.textContent).toMatch(/ago$/);
    expect(updated).toHaveAttribute("title", expect.stringContaining("2020"));
    // Created was demoted out of the card footer by the entity-list contract.
    expect(screen.queryByTestId(`banks-cards__created-${OLD.id}`)).toBeNull();
  });

  it("keeps the always-visible selection checkbox out of the data rows", () => {
    render(
      <BanksCards
        onBankDeleteFailed={() => {}}
        banks={[OLD]}
        selectedIds={new Set()}
        onToggleSelect={() => {}}
      />,
    );
    const checkbox = screen.getByTestId(`banks-cards__select-${OLD.id}`);
    // Always visible: no hover-reveal opacity classes on the checkbox.
    expect(checkbox.className).not.toContain("opacity-0");
  });
});
