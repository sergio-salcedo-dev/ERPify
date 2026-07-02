import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import StandaloneEditBankAccountPage from "@/app/backoffice/bank-accounts/[id]/edit/page";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";

const ACCOUNT_ID = vi.hoisted(() => "aaaaaaaa-aaaa-4aaa-8aaa-000000000001");

const ACCOUNT = BankAccount.fromPrimitives({
  id: ACCOUNT_ID,
  holderName: "Alice Holder",
  iban: "ES9121000418450200051332",
  bic: "CAIXESBBXXX",
  alias: "Payroll",
  currency: "EUR",
  status: "ACTIVE",
  bankId: "11111111-1111-4111-8111-111111111111",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-02-01T10:00:00Z",
});

vi.mock("next/navigation", async () =>
  (await import("./_mocks")).routerMock({}, { id: ACCOUNT_ID }),
);

const run = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({ BackOfficeFindBankAccount: { run } }),
);

// The status control owns its own DI + tests; stub it so this spec stays about
// the standalone edit scaffolding and the form's return target.
vi.mock("@/app/backoffice/banks/[id]/accounts/_components/BankAccountStatusControl", () => ({
  BankAccountStatusControl: () => null,
}));

describe("StandaloneEditBankAccountPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("loads the account and renders the edit form seeded from it", async () => {
    run.mockResolvedValue(ACCOUNT);
    render(<StandaloneEditBankAccountPage />);

    expect(await screen.findByTestId("bank-account-edit__title")).toHaveTextContent("Edit account");
    expect(screen.getByTestId("bank-account-form")).toBeInTheDocument();
    expect(
      (screen.getByTestId("bank-account-form__holder-name") as HTMLTextAreaElement).value,
    ).toBe("Alice Holder");
  });

  it("keeps Back and Cancel within the standalone hub (never under a bank)", async () => {
    run.mockResolvedValue(ACCOUNT);
    render(<StandaloneEditBankAccountPage />);

    expect(await screen.findByTestId("bank-account-edit__back-link")).toHaveAttribute(
      "href",
      "/backoffice/bank-accounts",
    );
    expect(screen.getByRole("link", { name: /cancel and go back to accounts/i })).toHaveAttribute(
      "href",
      "/backoffice/bank-accounts",
    );
  });
});
