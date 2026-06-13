import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { BanksTable } from "@/app/backoffice/banks/_components/BanksTable";
import BankDetailPage from "@/app/backoffice/banks/[id]/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
  useParams: () => ({ id: "11111111-1111-4111-8111-111111111111" }),
}));

const BASE = {
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T00:00:00Z",
  updatedAt: "2026-01-02T00:00:00Z",
};

const WITH_ACCOUNTS = Bank.fromPrimitives({ ...BASE, accountCount: 5 });
const WITHOUT_ACCOUNTS = Bank.fromPrimitives({ ...BASE, accountCount: 0 });

// --- BanksTable ACCOUNTS column ---

describe("BanksTable — ACCOUNTS column", () => {
  it("renders a link when accountCount > 0", () => {
    render(<BanksTable onBankDeleteFailed={() => {}} banks={[WITH_ACCOUNTS]} />);
    const cell = screen.getByTestId(`banks-table__accounts-${WITH_ACCOUNTS.id}`);
    expect(cell.tagName.toLowerCase()).toBe("a");
    expect(cell).toHaveTextContent("5");
    expect(cell).toHaveAttribute("href", expect.stringContaining("/accounts"));
  });

  it("renders a non-navigable cell when accountCount === 0 (invariant #1)", () => {
    render(<BanksTable onBankDeleteFailed={() => {}} banks={[WITHOUT_ACCOUNTS]} />);
    const cell = screen.getByTestId(`banks-table__accounts-${WITHOUT_ACCOUNTS.id}`);
    expect(cell).toHaveTextContent("0");
    // A 0 count must not navigate by ANY mechanism, not merely lack an <a href>:
    // no anchor (self or wrapping), no button, no role="link".
    expect(cell.tagName.toLowerCase()).toBe("span");
    expect(cell).not.toHaveAttribute("href");
    expect(cell).not.toHaveAttribute("role", "link");
    expect(cell.closest("a")).toBeNull();
    expect(cell.closest("button")).toBeNull();
    expect(cell.querySelector("a, button")).toBeNull();
  });
});

// --- BankDetailPage "Associated accounts" field ---

const run = vi.fn();
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: { get: () => ({ run }) },
}));

describe("BankDetailPage — Associated accounts field", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows count and View accounts link when accountCount > 0", async () => {
    run.mockResolvedValue(WITH_ACCOUNTS);
    render(<BankDetailPage />);
    const dd = await screen.findByTestId("banks-detail__field-accounts");
    expect(dd).toHaveTextContent("5");
    const link = dd.querySelector("a");
    expect(link).not.toBeNull();
    expect(link).toHaveAttribute("href", expect.stringContaining("/accounts"));
    expect(link).toHaveTextContent("View accounts");
  });

  it("shows None in muted when accountCount === 0", async () => {
    run.mockResolvedValue(WITHOUT_ACCOUNTS);
    render(<BankDetailPage />);
    const dd = await screen.findByTestId("banks-detail__field-accounts");
    expect(dd).toHaveTextContent("None");
    expect(dd.querySelector("a")).toBeNull();
  });
});
