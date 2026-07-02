import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import BankAccountsListPage from "@/app/backoffice/bank-accounts/page";
import type { BankAccountRealtimeEvent } from "@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime";
import { ACME_CHECKING, BETA_RESERVE, resourcePage } from "./_fixtures";

/**
 * The global collection topic carries PII-free deltas ({kind,id,bankId}); the
 * list must reconcile by a SILENT full refetch (Mercure has no replay), never by
 * promoting a partial delta straight into the ready list.
 */

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const search = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeBankAccountCrudRepository: { search },
  }),
);

vi.mock("@/context/shared/notification/infrastructure/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

const realtime = vi.hoisted(() => ({
  onCreated: undefined as ((event: BankAccountRealtimeEvent) => void) | undefined,
  onUpdated: undefined as ((event: BankAccountRealtimeEvent) => void) | undefined,
  onDeleted: undefined as ((event: BankAccountRealtimeEvent) => void) | undefined,
  onReconnect: undefined as (() => void) | undefined,
}));
vi.mock("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime", async () =>
  (await import("./_mocks")).bankAccountRealtimeMock((handlers) => {
    realtime.onCreated = handlers.onCreated;
    realtime.onUpdated = handlers.onUpdated;
    realtime.onDeleted = handlers.onDeleted;
    realtime.onReconnect = handlers.onReconnect;
  }),
);

function event(kind: BankAccountRealtimeEvent["kind"], id: string): BankAccountRealtimeEvent {
  return { kind, id, bankId: "11111111-1111-4111-8111-111111111111" };
}

describe("BankAccountsListPage — realtime over the global collection topic", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    realtime.onCreated = undefined;
    realtime.onUpdated = undefined;
    realtime.onDeleted = undefined;
    realtime.onReconnect = undefined;
  });

  it("silently reloads the full list when a created delta arrives while errored", async () => {
    search
      .mockRejectedValueOnce(new Error("network down"))
      .mockResolvedValueOnce(resourcePage([ACME_CHECKING, BETA_RESERVE]));

    render(<BankAccountsListPage />);
    await screen.findByTestId("bank-accounts-list__retry");
    expect(search).toHaveBeenCalledTimes(1);

    act(() => {
      realtime.onCreated?.(event("created", BETA_RESERVE.id));
    });

    await waitFor(() => {
      expect(screen.getByRole("cell", { name: "Alice Holder" })).toBeInTheDocument();
    });
    // ACME was never in the delta — its presence proves the full list reloaded.
    expect(screen.getByRole("cell", { name: "Bob Holder" })).toBeInTheDocument();
    expect(search).toHaveBeenCalledTimes(2);
    expect(screen.queryByTestId("bank-accounts-list__retry")).toBeNull();
  });

  it("silently reloads on an updated delta and on reconnect", async () => {
    search
      .mockResolvedValueOnce(resourcePage([ACME_CHECKING]))
      .mockResolvedValueOnce(resourcePage([ACME_CHECKING, BETA_RESERVE]))
      .mockResolvedValueOnce(resourcePage([ACME_CHECKING, BETA_RESERVE]));

    render(<BankAccountsListPage />);
    await screen.findByTestId(`bank-accounts-list__row-${ACME_CHECKING.id}`);
    expect(search).toHaveBeenCalledTimes(1);

    act(() => {
      realtime.onUpdated?.(event("updated", ACME_CHECKING.id));
    });
    await waitFor(() => expect(search).toHaveBeenCalledTimes(2));

    act(() => {
      realtime.onReconnect?.();
    });
    await waitFor(() => expect(search).toHaveBeenCalledTimes(3));
  });

  it("silently reloads the list when a deleted delta arrives", async () => {
    search
      .mockResolvedValueOnce(resourcePage([ACME_CHECKING, BETA_RESERVE]))
      .mockResolvedValueOnce(resourcePage([ACME_CHECKING]));

    render(<BankAccountsListPage />);
    await screen.findByTestId(`bank-accounts-list__row-${BETA_RESERVE.id}`);

    act(() => {
      realtime.onDeleted?.(event("deleted", BETA_RESERVE.id));
    });

    await waitFor(() => {
      expect(screen.queryByTestId(`bank-accounts-list__row-${BETA_RESERVE.id}`)).toBeNull();
    });
    expect(search).toHaveBeenCalledTimes(2);
  });
});
