import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import BankAccountDetailPage from "@/app/backoffice/bank-accounts/[id]/page";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import type { BankAccountRealtimeEvent } from "@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime";

/**
 * The detail page subscribes to the per-account topic: an `updated` delta
 * refetches (the payload is PII-free), a `deleted` delta redirects to the list,
 * and a reconnect reconciles by a silent refetch.
 */

const ACCOUNT_ID = vi.hoisted(() => "aaaaaaaa-aaaa-4aaa-8aaa-000000000001");

const ACCOUNT = BankAccount.fromPrimitives({
  id: ACCOUNT_ID,
  holderName: "Alice Holder",
  iban: "ES9121000418450200051332",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "ACTIVE",
  bankId: "11111111-1111-4111-8111-111111111111",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-02-01T10:00:00Z",
});

const push = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", async () =>
  (await import("./_mocks")).routerMock({ push }, { id: ACCOUNT_ID }),
);

const run = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({ BackOfficeFindBankAccount: { run } }),
);

vi.mock("@/context/shared/notification/infrastructure/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

const realtime = vi.hoisted(() => ({
  onUpdated: undefined as ((event: BankAccountRealtimeEvent) => void) | undefined,
  onDeleted: undefined as ((event: BankAccountRealtimeEvent) => void) | undefined,
  onReconnect: undefined as (() => void) | undefined,
}));
vi.mock("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime", async () =>
  (await import("./_mocks")).bankAccountRealtimeMock((handlers) => {
    realtime.onUpdated = handlers.onUpdated;
    realtime.onDeleted = handlers.onDeleted;
    realtime.onReconnect = handlers.onReconnect;
  }),
);

function event(kind: BankAccountRealtimeEvent["kind"], id: string): BankAccountRealtimeEvent {
  return { kind, id, bankId: ACCOUNT.bankId ?? "" };
}

describe("BankAccountDetailPage — realtime", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    realtime.onUpdated = undefined;
    realtime.onDeleted = undefined;
    realtime.onReconnect = undefined;
  });

  it("refetches the account when an updated delta arrives for this id", async () => {
    run.mockResolvedValue(ACCOUNT);
    render(<BankAccountDetailPage />);
    await screen.findByTestId("bank-accounts-detail__holder");
    expect(run).toHaveBeenCalledTimes(1);

    act(() => {
      realtime.onUpdated?.(event("updated", ACCOUNT.id));
    });

    await waitFor(() => expect(run).toHaveBeenCalledTimes(2));
  });

  it("redirects to the list when a deleted delta arrives for this id", async () => {
    run.mockResolvedValue(ACCOUNT);
    render(<BankAccountDetailPage />);
    await screen.findByTestId("bank-accounts-detail__holder");

    act(() => {
      realtime.onDeleted?.(event("deleted", ACCOUNT.id));
    });

    await waitFor(() => {
      expect(push).toHaveBeenCalledWith("/backoffice/bank-accounts");
    });
    expect(screen.getByTestId("bank-accounts-detail__redirecting")).toBeInTheDocument();
  });

  it("silently reconciles on reconnect", async () => {
    run.mockResolvedValue(ACCOUNT);
    render(<BankAccountDetailPage />);
    await screen.findByTestId("bank-accounts-detail__holder");
    expect(run).toHaveBeenCalledTimes(1);

    act(() => {
      realtime.onReconnect?.();
    });

    await waitFor(() => expect(run).toHaveBeenCalledTimes(2));
  });
});
