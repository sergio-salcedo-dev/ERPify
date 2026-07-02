import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BankAccountDetailPage from "@/app/backoffice/bank-accounts/[id]/page";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { maskIban } from "@/app/backoffice/banks/[id]/accounts/_components/IbanCell";

const ACCOUNT_ID = vi.hoisted(() => "aaaaaaaa-aaaa-4aaa-8aaa-000000000001");

const BASE = {
  id: ACCOUNT_ID,
  holderName: "Alice Holder",
  iban: "ES9121000418450200051332",
  bic: "CAIXESBBXXX",
  alias: "Payroll",
  currency: "EUR",
  bankId: "11111111-1111-4111-8111-111111111111",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-02-01T10:00:00Z",
} as const;

const ACCOUNT = BankAccount.fromPrimitives({ ...BASE, status: "ACTIVE" });
const CLOSED_ACCOUNT = BankAccount.fromPrimitives({ ...BASE, status: "CLOSED" });

const push = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", async () =>
  (await import("./_mocks")).routerMock({ push }, { id: ACCOUNT_ID }),
);

const run = vi.hoisted(() => vi.fn());
const deleteRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeFindBankAccount: { run },
    BackOfficeDeleteBankAccount: { run: deleteRun },
  }),
);

const toast = vi.hoisted(() => ({
  success: vi.fn(),
  error: vi.fn(),
  info: vi.fn(),
  warning: vi.fn(),
}));
vi.mock("@/context/shared/notification/infrastructure/Toast", async () => ({
  toastNotifier: toast,
}));

vi.mock("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime", async () =>
  (await import("./_mocks")).bankAccountRealtimeMock(),
);

describe("BankAccountDetailPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("renders the account with a masked IBAN and the status badge", async () => {
    run.mockResolvedValue(ACCOUNT);
    render(<BankAccountDetailPage />);

    expect(await screen.findByTestId("bank-accounts-detail__holder")).toHaveTextContent(
      "Alice Holder",
    );
    expect(screen.getByTestId("bank-accounts-detail__status")).toHaveTextContent("Active");

    const iban = screen.getByTestId("bank-accounts-detail__iban");
    expect(iban).toHaveTextContent(maskIban(ACCOUNT.iban));
    expect(iban.textContent).not.toContain(ACCOUNT.iban);
  });

  it("links Edit to the account's standalone edit form (bank-agnostic)", async () => {
    run.mockResolvedValue(ACCOUNT);
    render(<BankAccountDetailPage />);

    const edit = await screen.findByTestId("bank-accounts-detail__edit-button");
    expect(edit).toHaveAttribute(
      "href",
      "/backoffice/bank-accounts/aaaaaaaa-aaaa-4aaa-8aaa-000000000001/edit",
    );
  });

  it("guards Delete on a non-CLOSED account and offers the standalone edit as recovery", async () => {
    run.mockResolvedValue(ACCOUNT);
    render(<BankAccountDetailPage />);

    fireEvent.click(await screen.findByTestId("bank-accounts-detail__delete-button"));

    expect(await screen.findByTestId("bank-accounts-detail__delete-guard-message")).toBeVisible();
    expect(screen.getByTestId("bank-accounts-detail__delete-guard-edit")).toHaveAttribute(
      "href",
      "/backoffice/bank-accounts/aaaaaaaa-aaaa-4aaa-8aaa-000000000001/edit",
    );
    expect(deleteRun).not.toHaveBeenCalled();
  });

  it("deletes a CLOSED account, then toasts and redirects to the list", async () => {
    run.mockResolvedValue(CLOSED_ACCOUNT);
    deleteRun.mockResolvedValue(undefined);
    render(<BankAccountDetailPage />);

    fireEvent.click(await screen.findByTestId("bank-accounts-detail__delete-button"));
    fireEvent.click(await screen.findByTestId("bank-accounts-detail__delete-confirm"));

    await waitFor(() => expect(deleteRun).toHaveBeenCalledWith(ACCOUNT_ID));
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith("Account deleted", {
        description: "Alice Holder",
      }),
    );
    await waitFor(() => expect(push).toHaveBeenCalledWith("/backoffice/bank-accounts"));
  });

  it("shows a typed not-found state with the correlation id on a 404", async () => {
    run.mockRejectedValue(
      new HttpError({
        type: "bank-account-not-found",
        title: "Account not found.",
        status: HttpStatus.NOT_FOUND,
        detail: "It may have been deleted.",
        instance: "01H-instance",
        "correlation-id": "01H-correlation",
      }),
    );
    render(<BankAccountDetailPage />);

    expect(await screen.findByTestId("bank-accounts-detail__not-found")).toBeInTheDocument();
    expect(screen.getByText("01H-correlation")).toBeInTheDocument();
    expect(screen.getByTestId("bank-accounts-detail__back-to-list")).toBeInTheDocument();
  });
});
