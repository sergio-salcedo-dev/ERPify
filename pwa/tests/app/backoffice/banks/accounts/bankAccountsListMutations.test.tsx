import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import BankAccountsPage from "@/app/backoffice/banks/[id]/accounts/page";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import type { BankAccountSearchPage } from "@/context/backoffice/bankaccount/domain/BankAccountRepository";
import type { BankAccountRealtimeHandlers } from "@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import { openRowDeleteItem } from "../_interactions";

const BANK_ID = "11111111-1111-7111-8111-111111111111";

const searchRun = vi.hoisted(() => vi.fn());
const deleteRun = vi.hoisted(() => vi.fn());
const realtime = vi.hoisted(() => ({
  handlers: undefined as BankAccountRealtimeHandlers | undefined,
}));

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: BANK_ID }),
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeSearchBankAccounts") return { run: searchRun };
      if (token === "BackOfficeBankAccountSearchNavigator") return { follow: vi.fn() };
      if (token === "BackOfficeDeleteBankAccount") return { run: deleteRun };
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

// Replace the Mercure subscription (its live fetch/EventSource churn flakes under
// jsdom) while capturing the handlers, so tests drive realtime deltas directly.
vi.mock("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime", async () => {
  const actual = await vi.importActual<
    typeof import("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime")
  >("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime");
  return {
    ...actual,
    useBankAccountRealtime: (_topics: readonly string[], handlers: BankAccountRealtimeHandlers) => {
      realtime.handlers = handlers;
    },
  };
});

const CLOSED = BankAccount.fromPrimitives({
  id: "0190ffff-aaaa-7bbb-8ccc-000000000001",
  holderName: "Closed Holder",
  iban: "ES9121000418450200051332",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "CLOSED",
});

const ACTIVE = BankAccount.fromPrimitives({
  id: "0190ffff-aaaa-7bbb-8ccc-000000000002",
  holderName: "Active Holder",
  iban: "DE89370400440532013000",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "ACTIVE",
});

function page(accounts: BankAccount[]): BankAccountSearchPage {
  return {
    accounts,
    hasNext: false,
    hasPrev: false,
    count: null,
    links: { next: null, prev: null },
  };
}

const NOT_CLOSED_PROBLEM: ProblemDetails = {
  type: "bank-account-not-closed",
  title: "Account cannot be deleted: it is not closed",
  status: 409,
  detail: "Set the account status to Closed before deleting it.",
  instance: "01H-409-instance",
  "correlation-id": "01H-409",
};

async function openRowDelete(id: string): Promise<void> {
  fireEvent.click(await openRowDeleteItem("bank-accounts-table", id));
}

describe("BankAccountsPage — mutations hub", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    realtime.handlers = undefined;
    searchRun.mockResolvedValue(page([CLOSED, ACTIVE]));
    deleteRun.mockResolvedValue(undefined);
  });

  it("exposes a New account button pointing at the nested create route", async () => {
    render(<BankAccountsPage />);
    const newButton = await screen.findByTestId("bank-accounts__new-button");
    expect(newButton).toHaveAttribute("href", `/backoffice/banks/${BANK_ID}/accounts/new`);
  });

  it("optimistic guard: a non-CLOSED account shows the guard (no confirm, no DELETE)", async () => {
    render(<BankAccountsPage />);
    await screen.findByTestId(`bank-accounts-table__row-${ACTIVE.id}`);

    await openRowDelete(ACTIVE.id);

    expect(
      await screen.findByTestId("bank-accounts-row__delete-guard-message"),
    ).toBeInTheDocument();
    expect(screen.getByTestId("bank-accounts-row__delete-guard-edit")).toHaveAttribute(
      "href",
      `/backoffice/banks/${BANK_ID}/accounts/${ACTIVE.id}/edit`,
    );
    expect(screen.queryByTestId("bank-accounts-row__delete-confirm")).toBeNull();
    expect(deleteRun).not.toHaveBeenCalled();
  });

  it("deletes a CLOSED account, toasts success, and silently reloads the list", async () => {
    render(<BankAccountsPage />);
    await screen.findByTestId(`bank-accounts-table__row-${CLOSED.id}`);
    expect(searchRun).toHaveBeenCalledTimes(1);

    await openRowDelete(CLOSED.id);
    fireEvent.click(await screen.findByTestId("bank-accounts-row__delete-confirm"));

    await waitFor(() => expect(deleteRun).toHaveBeenCalledWith(CLOSED.id));
    await waitFor(() =>
      expect(toastNotifier.success).toHaveBeenCalledWith("Account deleted", {
        description: "Closed Holder",
      }),
    );
    // Silent reload re-runs the search to reconcile the removed row.
    await waitFor(() => expect(searchRun).toHaveBeenCalledTimes(2));
  });

  it("a raced 409 not-closed lands in the persistent surface with an Edit recovery; the row stays", async () => {
    deleteRun.mockRejectedValue(new HttpError(NOT_CLOSED_PROBLEM));
    render(<BankAccountsPage />);
    await screen.findByTestId(`bank-accounts-table__row-${CLOSED.id}`);

    await openRowDelete(CLOSED.id);
    fireEvent.click(await screen.findByTestId("bank-accounts-row__delete-confirm"));

    const surface = await screen.findByTestId("bank-accounts__delete-error");
    expect(surface).toHaveTextContent(NOT_CLOSED_PROBLEM.title);
    expect(screen.getByTestId("problem-display__type")).toHaveTextContent(
      "bank-account-not-closed",
    );
    expect(screen.getByTestId("bank-accounts__delete-error-edit")).toHaveAttribute(
      "href",
      `/backoffice/banks/${BANK_ID}/accounts/${CLOSED.id}/edit`,
    );
    expect(screen.queryByTestId("bank-accounts__delete-error-refresh")).toBeNull();
    expect(screen.getByTestId(`bank-accounts-table__row-${CLOSED.id}`)).toBeInTheDocument();
    expect(toastNotifier.error).toHaveBeenCalledWith("Couldn't delete account — see error details");
  });

  it("refetches the list silently on a realtime delta", async () => {
    render(<BankAccountsPage />);
    await screen.findByTestId(`bank-accounts-table__row-${CLOSED.id}`);
    await waitFor(() => expect(searchRun).toHaveBeenCalledTimes(1));

    searchRun.mockResolvedValue(page([CLOSED]));
    act(() => {
      realtime.handlers?.onDeleted?.({ kind: "deleted", id: ACTIVE.id, bankId: BANK_ID });
    });

    await waitFor(() => expect(searchRun).toHaveBeenCalledTimes(2));
    await waitFor(() =>
      expect(screen.queryByTestId(`bank-accounts-table__row-${ACTIVE.id}`)).toBeNull(),
    );
  });
});
