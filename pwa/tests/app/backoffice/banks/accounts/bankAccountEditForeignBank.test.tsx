import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import EditBankAccountPage from "@/app/backoffice/banks/[id]/accounts/[accountId]/edit/page";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";

/**
 * Opening an account under the wrong bank's URL: the account API is not bank-scoped, so the row
 * loads, but a foreign account (its bankId differs from the route's) is rejected as not-found
 * client-side. The not-found empty state must render — it needs a synthetic problem to show its
 * correlation chip, so a mismatch that set the state without a problem would strand the user on a
 * near-blank page.
 */

const ROUTE_BANK_ID = "11111111-1111-7111-8111-111111111111";
const OTHER_BANK_ID = "22222222-2222-7222-8222-222222222222";
const ACCOUNT_ID = "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c";

const findRun = vi.hoisted(() => vi.fn());

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: ROUTE_BANK_ID, accountId: ACCOUNT_ID }),
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeFindBankAccount") return { run: findRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    dismissAll: vi.fn(),
  },
}));

const FOREIGN_ACCOUNT = BankAccount.fromPrimitives({
  id: ACCOUNT_ID,
  bankId: OTHER_BANK_ID,
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "ACTIVE",
  createdAt: "2026-01-01T00:00:00+00:00",
  updatedAt: "2026-01-02T00:00:00+00:00",
});

describe("EditBankAccountPage — foreign-bank account", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("renders the not-found empty state when the account belongs to another bank", async () => {
    findRun.mockResolvedValue(FOREIGN_ACCOUNT);

    render(<EditBankAccountPage />);

    expect(await screen.findByTestId("bank-accounts-edit__not-found")).toBeInTheDocument();
    expect(screen.getByTestId("bank-accounts-edit__back-to-accounts")).toBeInTheDocument();
    expect(screen.queryByTestId("bank-account-form")).toBeNull();
  });
});
