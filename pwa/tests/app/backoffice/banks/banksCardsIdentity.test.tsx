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
});

const OLD = Bank.fromPrimitives({
  id: "44444444-4444-4444-8444-444444444444",
  name: "Caixa Bank",
  shortName: "CAIX",
  createdAt: "2020-01-01T00:00:00.000Z",
  updatedAt: "2020-01-01T00:00:00.000Z",
});

describe("BanksCards — identity", () => {
  it("shows a New badge only for recently created banks", () => {
    render(<BanksCards banks={[RECENT, OLD]} />);
    expect(screen.getByTestId(`banks-cards__new-${RECENT.id}`)).toHaveTextContent("New");
    expect(screen.queryByTestId(`banks-cards__new-${OLD.id}`)).toBeNull();
  });

  it("renders created/updated as relative text with the absolute value in the title", () => {
    render(<BanksCards banks={[OLD]} />);
    const created = screen.getByTestId(`banks-cards__created-${OLD.id}`);
    expect(created.textContent).toMatch(/ago$/);
    expect(created).toHaveAttribute("title", expect.stringContaining("2020"));
    const updated = screen.getByTestId(`banks-cards__updated-${OLD.id}`);
    expect(updated.textContent).toMatch(/ago$/);
    expect(updated).toHaveAttribute("title", expect.stringContaining("2020"));
  });
});
