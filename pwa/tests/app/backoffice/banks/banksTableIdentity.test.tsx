import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { BanksTable } from "@/app/backoffice/banks/_components/BanksTable";
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
});

const OLD = Bank.fromPrimitives({
  id: "22222222-2222-4222-8222-222222222222",
  name: "Caixa Bank",
  shortName: "CAIX",
  createdAt: "2020-01-01T00:00:00.000Z",
  updatedAt: "2020-01-01T00:00:00.000Z",
});

describe("BanksTable — identity cell", () => {
  it("renders a monogram avatar in the name cell", () => {
    render(<BanksTable banks={[RECENT]} />);
    expect(screen.getByTestId(`banks-table__avatar-${RECENT.id}`)).toHaveTextContent("SB");
  });

  it("shows a New badge for a recently created bank and not for an old one", () => {
    render(<BanksTable banks={[RECENT, OLD]} />);
    expect(screen.getByTestId(`banks-table__new-${RECENT.id}`)).toHaveTextContent("New");
    expect(screen.queryByTestId(`banks-table__new-${OLD.id}`)).toBeNull();
  });

  it("renders the created cell as relative text with the absolute value in the title", () => {
    render(<BanksTable banks={[OLD]} />);
    const cell = screen.getByTestId(`banks-table__created-${OLD.id}`);
    expect(cell.textContent).toMatch(/ago$/);
    // Absolute dd/mm/yyyy value lives in the tooltip.
    expect(cell).toHaveAttribute("title", expect.stringContaining("2020"));
  });
});
