import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { BanksTable } from "@/app/backoffice/banks/_components/BanksTable";
import { BANK_COLUMN_KEYS } from "@/app/backoffice/banks/_lib/bankColumns";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

const RECENT = Bank.fromPrimitives({
  id: "11111111-1111-4111-8111-111111111111",
  name: "Santander Bank",
  shortName: "SANB",
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
  accountCount: 0,
});

const OLD = Bank.fromPrimitives({
  id: "22222222-2222-4222-8222-222222222222",
  name: "Caixa Bank",
  shortName: "CAIX",
  createdAt: "2020-01-01T00:00:00.000Z",
  updatedAt: "2020-01-01T00:00:00.000Z",
  accountCount: 0,
});

describe("BanksTable — identity cell", () => {
  it("shows a New badge for a recently created bank and not for an old one", () => {
    render(
      <BanksTable
        onBankDeleteFailed={() => {}}
        banks={[RECENT, OLD]}
        visible={[...BANK_COLUMN_KEYS]}
      />,
    );
    expect(screen.getByTestId(`banks-table__new-${RECENT.id}`)).toHaveTextContent("New");
    expect(screen.queryByTestId(`banks-table__new-${OLD.id}`)).toBeNull();
  });

  it("renders the created cell as relative text with the absolute value in the title", () => {
    render(
      <BanksTable onBankDeleteFailed={() => {}} banks={[OLD]} visible={[...BANK_COLUMN_KEYS]} />,
    );
    const cell = screen.getByTestId(`banks-table__created-${OLD.id}`);
    expect(cell.textContent).toMatch(/ago$/);
    // Absolute dd/mm/yyyy value lives in the tooltip.
    expect(cell).toHaveAttribute("title", expect.stringContaining("2020"));
  });
});
