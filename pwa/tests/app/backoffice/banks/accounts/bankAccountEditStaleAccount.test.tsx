import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import EditBankAccountPage from "@/app/backoffice/banks/[id]/accounts/[accountId]/edit/page";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";

/**
 * Editing an account a colleague deleted in parallel: the PUT answers 404
 * `bank-account-not-found`, the persistent surface offers "Refresh", and Refresh
 * re-runs the page load — which now 404s — landing on the not-found empty state
 * with focus on its "Back to accounts" CTA (never <body>).
 */

const BANK_ID = "11111111-1111-7111-8111-111111111111";
const ACCOUNT_ID = "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c";

const push = vi.hoisted(() => vi.fn());
const findRun = vi.hoisted(() => vi.fn());
const updateRun = vi.hoisted(() => vi.fn());

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: BANK_ID, accountId: ACCOUNT_ID }),
  useRouter: () => ({ push, refresh: vi.fn(), back: vi.fn() }),
}));

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeFindBankAccount") return { run: findRun };
      if (token === "BackOfficeUpdateBankAccount") return { run: updateRun };
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

const ACCOUNT = BankAccount.fromPrimitives({
  id: ACCOUNT_ID,
  bankId: BANK_ID,
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "ACTIVE",
  createdAt: "2026-01-01T00:00:00+00:00",
  updatedAt: "2026-01-02T00:00:00+00:00",
});

const STALE_PROBLEM: ProblemDetails = {
  type: "bank-account-not-found",
  title: "Account not found",
  status: 404,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000404",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000404",
};

describe("EditBankAccountPage — stale account recovery", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    findRun.mockResolvedValue(ACCOUNT);
  });

  it("PUT 404 → Refresh → not-found empty state with focus on the back-to-accounts CTA", async () => {
    updateRun.mockRejectedValue(new HttpError(STALE_PROBLEM));
    render(<EditBankAccountPage />);
    await screen.findByTestId("bank-account-form");

    fireEvent.change(screen.getByTestId("bank-account-form__holder-name"), {
      target: { value: "Renamed Holder" },
    });
    fireEvent.submit(screen.getByTestId("bank-account-form"));

    const surface = await screen.findByTestId("bank-account-form__mutation-error");
    expect(surface).toHaveTextContent("Account not found");

    // The re-load now finds the account gone.
    findRun.mockRejectedValue(new HttpError(STALE_PROBLEM));
    fireEvent.click(screen.getByTestId("bank-account-form__mutation-error-refresh"));

    const backToAccounts = await screen.findByTestId("bank-accounts-edit__back-to-accounts");
    expect(screen.getByTestId("bank-accounts-edit__not-found")).toBeInTheDocument();
    expect(screen.queryByTestId("bank-account-form")).toBeNull();
    expect(screen.queryByTestId("bank-account-form__mutation-error")).toBeNull();
    await waitFor(() => expect(backToAccounts).toHaveFocus());
  });
});
