import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { BanksCards } from "@/app/backoffice/banks/_components/BanksCards";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const LONG = Bank.fromPrimitives({
  id: "55555555-5555-4555-8555-555555555555",
  name: "Banco Nacional de Comercio Exterior",
  shortName: "BNCEMX0001LONGUNBROKENCODE",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-04-15T10:00:00Z",
});

describe("BanksCards — layout", () => {
  it("renders the full name without truncation so long names stay readable", () => {
    render(<BanksCards banks={[LONG]} />);
    const name = screen.getByTestId(`banks-cards__name-${LONG.id}`);
    expect(name).toHaveTextContent("Banco Nacional de Comercio Exterior");
    expect(name.className).not.toContain("truncate");
  });

  it("renders the full short name without truncation", () => {
    render(<BanksCards banks={[LONG]} />);
    const shortName = screen.getByTestId(`banks-cards__shortname-${LONG.id}`);
    expect(shortName).toHaveTextContent("BNCEMX0001LONGUNBROKENCODE");
    expect(shortName.className).not.toContain("truncate");
  });

  it("drops the redundant footer view-details link (whole card navigates)", () => {
    render(<BanksCards banks={[LONG]} />);
    expect(screen.queryByTestId(`banks-cards__view-${LONG.id}`)).toBeNull();
  });

  it("exposes copy and edit controls plus an overflow actions trigger per card", () => {
    render(<BanksCards banks={[LONG]} />);
    expect(screen.getByTestId(`banks-cards__copy-${LONG.id}`)).toBeInTheDocument();
    expect(screen.getByTestId(`banks-cards__edit-${LONG.id}`)).toBeInTheDocument();
    expect(screen.getByTestId(`banks-cards__actions-${LONG.id}`)).toBeInTheDocument();
  });
});
