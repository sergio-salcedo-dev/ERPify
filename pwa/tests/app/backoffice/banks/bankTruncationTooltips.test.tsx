import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { BanksTable } from "@/app/backoffice/banks/_components/BanksTable";
import { BanksCards } from "@/app/backoffice/banks/_components/BanksCards";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const LONG = Bank.fromPrimitives({
  id: "33333333-3333-4333-8333-333333333333",
  name: "Very Long Bank Name That Wraps",
  shortName: "VERYLONGSHORTNAMEVALUE",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-01-01T10:00:00Z",
});

describe("Bank short-name truncation tooltips", () => {
  it("table short-name cell exposes the full value via title", () => {
    render(<BanksTable banks={[LONG]} />);
    const el = screen.getByText("VERYLONGSHORTNAMEVALUE");
    expect(el).toHaveAttribute("title", "VERYLONGSHORTNAMEVALUE");
  });

  it("card short-name exposes the full value via title", () => {
    render(<BanksCards banks={[LONG]} />);
    const el = screen.getByTestId(`banks-cards__shortname-${LONG.id}`);
    expect(el).toHaveAttribute("title", "VERYLONGSHORTNAMEVALUE");
  });
});
