import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { BankAccountStatusControl } from "@/app/backoffice/banks/[id]/accounts/_components/BankAccountStatusControl";
import {
  BankAccount,
  type BankAccountStatus,
} from "@/context/backoffice/bankaccount/domain/BankAccount";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const changeRun = vi.hoisted(() => vi.fn());

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeChangeBankAccountStatus") return { run: changeRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

function account(status: BankAccountStatus = "ACTIVE"): BankAccount {
  return BankAccount.fromPrimitives({
    id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
    bankId: "11111111-1111-7111-8111-111111111111",
    holderName: "Acme Corp",
    iban: "ES9121000418450200051332",
    bic: null,
    alias: null,
    currency: "EUR",
    status,
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-02T00:00:00+00:00",
  });
}

function problem(overrides: Partial<ProblemDetails> = {}): ProblemDetails {
  return {
    type: "unhandled-exception",
    title: "Something went wrong.",
    status: 500,
    instance: "01H-instance",
    "correlation-id": "01H-correlation",
    ...overrides,
  };
}

describe("BankAccountStatusControl", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("save is disabled until the status changes, then PATCHes and hands the account back", async () => {
    const onChanged = vi.fn();
    changeRun.mockResolvedValueOnce(account("CLOSED"));
    render(<BankAccountStatusControl account={account("ACTIVE")} onChanged={onChanged} />);

    const save = screen.getByTestId("bank-account-status__save");
    expect(save).toBeDisabled();

    fireEvent.change(screen.getByTestId("bank-account-status__select"), {
      target: { value: "CLOSED" },
    });
    expect(save).toBeEnabled();
    fireEvent.click(save);

    await waitFor(() =>
      expect(changeRun).toHaveBeenCalledWith("0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c", "CLOSED"),
    );
    expect(onChanged).toHaveBeenCalledWith(expect.objectContaining({ status: "CLOSED" }));
  });

  it("surfaces a server problem in the persistent error surface and does not report success", async () => {
    const onChanged = vi.fn();
    changeRun.mockRejectedValueOnce(
      new HttpError(problem({ status: 404, type: "bank-account-not-found" })),
    );
    render(<BankAccountStatusControl account={account("ACTIVE")} onChanged={onChanged} />);

    fireEvent.change(screen.getByTestId("bank-account-status__select"), {
      target: { value: "INACTIVE" },
    });
    fireEvent.click(screen.getByTestId("bank-account-status__save"));

    await waitFor(() =>
      expect(screen.getByTestId("bank-account-status__error")).toBeInTheDocument(),
    );
    expect(onChanged).not.toHaveBeenCalled();
  });
});
