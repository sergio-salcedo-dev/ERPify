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

/**
 * The native `title=` tooltips were replaced by the tooltip-if-truncated
 * pattern (CSS-only truncation; the full string always stays in the DOM and
 * the accessibility tree; a Tooltip mounts only when the text actually
 * truncates — `TruncatedText` owns that behavior). These tests lock the
 * non-negotiables: full value in the DOM, no `title=` shadow copy.
 */
describe("Bank short-name truncation", () => {
  it("table code cell keeps the full value in the DOM, truncating via CSS only", () => {
    render(<BanksTable onBankDeleteFailed={() => {}} banks={[LONG]} />);
    const el = screen.getByText("VERYLONGSHORTNAMEVALUE");
    expect(el).not.toHaveAttribute("title");
    expect(el).toHaveClass("truncate");
  });

  it("card short-name keeps the full value in the DOM, truncating via CSS only", () => {
    render(<BanksCards onBankDeleteFailed={() => {}} banks={[LONG]} />);
    const el = screen.getByTestId(`banks-cards__shortname-${LONG.id}`);
    expect(el).toHaveTextContent("VERYLONGSHORTNAMEVALUE");
    expect(el).not.toHaveAttribute("title");
    expect(el).toHaveClass("truncate");
  });

  it("table name cell keeps the full 255-char value in the DOM", () => {
    const name = "N".repeat(255);
    const longest = Bank.fromPrimitives({
      id: "44444444-4444-4444-8444-444444444444",
      name,
      shortName: "LONGEST",
      createdAt: "2026-01-01T10:00:00Z",
      updatedAt: "2026-01-01T10:00:00Z",
    });
    render(<BanksTable onBankDeleteFailed={() => {}} banks={[longest]} />);
    expect(screen.getByText(name)).toBeInTheDocument();
  });
});
