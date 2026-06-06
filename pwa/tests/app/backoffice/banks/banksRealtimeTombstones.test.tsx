import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { BankRealtimeHandlers } from "@/context/backoffice/bank/infrastructure/bankRealtime";
import { ACME, BETA } from "./_fixtures";

/**
 * Tombstones close the two row-resurrection windows: a late at-least-once
 * redelivery of `created` after a delete, and a Mercure `deleted` processed
 * during the bulk-restore re-probe window. A successful reload prunes the
 * set — the server list is authoritative.
 */

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const searchRun = vi.hoisted(() => vi.fn());
const deleteRun = vi.hoisted(() => vi.fn());
const findRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeSearchBanks: { run: searchRun },
    BackOfficeDeleteBank: { run: deleteRun },
    BackOfficeFindBank: { run: findRun },
  }),
);

vi.mock("@/context/shared/infrastructure/Notification/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

// Replace the Mercure subscription while capturing the handlers, so tests can
// drive realtime deltas directly.
let realtimeHandlers: BankRealtimeHandlers | undefined;
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", async () =>
  (await import("./_mocks")).bankRealtimeMock((handlers) => {
    realtimeHandlers = handlers;
  }),
);

async function renderWithRows(): Promise<void> {
  render(<BanksListPage />);
  await screen.findByTestId(`banks-table__row-${ACME.id}`);
}

function selectRows(...banks: Bank[]): void {
  for (const bank of banks) {
    fireEvent.click(screen.getByLabelText(`Select row ${bank.id}`));
  }
}

async function confirmBulkDelete(): Promise<void> {
  fireEvent.click(screen.getByTestId("banks-list__bulk-delete"));
  fireEvent.click(await screen.findByTestId("banks-list__bulk-delete-confirm"));
}

describe("BanksListPage — deleted-id tombstones", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    realtimeHandlers = undefined;
    searchRun.mockResolvedValue({ banks: [ACME, BETA], nextCursor: undefined });
    deleteRun.mockResolvedValue(undefined);
    findRun.mockImplementation((id: string) => Promise.resolve(id === ACME.id ? ACME : BETA));
  });

  it("does not resurrect a deleted row on a late `created` redelivery", async () => {
    await renderWithRows();

    act(() => realtimeHandlers?.onDeleted?.(ACME.id));
    await waitFor(() => {
      expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    });

    // At-least-once redelivery of the original `created` arrives after the
    // delete — the tombstone keeps the row gone.
    act(() => realtimeHandlers?.onCreated?.(ACME));

    expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    expect(screen.getByTestId(`banks-table__row-${BETA.id}`)).toBeInTheDocument();
  });

  it("prunes the tombstones on a successful reload — the server list wins", async () => {
    await renderWithRows();

    act(() => realtimeHandlers?.onDeleted?.(ACME.id));
    await waitFor(() => {
      expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    });

    // A reconnect reconciles with the server (which no longer lists ACME) and
    // empties the tombstone set.
    searchRun.mockResolvedValue({ banks: [BETA], nextCursor: undefined });
    act(() => realtimeHandlers?.onReconnect?.());
    await waitFor(() => {
      expect(searchRun).toHaveBeenCalledTimes(2);
    });

    // ACME is genuinely re-created later: with the tombstone pruned, the
    // `created` event inserts it again.
    act(() => realtimeHandlers?.onCreated?.(ACME));

    expect(await screen.findByTestId(`banks-table__row-${ACME.id}`)).toBeInTheDocument();
  });

  it("does not restore nor re-select a row whose Mercure delete lands during the bulk-restore window", async () => {
    // BETA's delete fails with a non-404 (restorable); its re-probe is held
    // open so the test can slip a Mercure `deleted` into the window.
    deleteRun.mockImplementation((id: string) =>
      id === BETA.id ? Promise.reject(new Error("boom")) : Promise.resolve(undefined),
    );
    let resolveReprobe: ((bank: Bank) => void) | undefined;
    const findCalls = new Map<string, number>();
    findRun.mockImplementation((id: string) => {
      const count = (findCalls.get(id) ?? 0) + 1;
      findCalls.set(id, count);
      if (id === BETA.id && count >= 2) {
        return new Promise<Bank>((resolve) => {
          resolveReprobe = resolve;
        });
      }
      return Promise.resolve(id === ACME.id ? ACME : BETA);
    });
    await renderWithRows();

    selectRows(ACME, BETA);
    await confirmBulkDelete();

    // The failure surfaced and the re-probe round-trip is in flight.
    await screen.findByTestId("banks-list__delete-error");
    await waitFor(() => {
      expect(resolveReprobe).toBeDefined();
    });

    // Another client's delete is confirmed by the server mid-window…
    act(() => realtimeHandlers?.onDeleted?.(BETA.id));
    // …then the stale re-probe resolves claiming the bank still exists.
    await act(async () => {
      resolveReprobe?.(BETA);
    });

    // The tombstone outranks the fail-open restore: no row, no re-selection.
    expect(screen.queryByTestId(`banks-table__row-${BETA.id}`)).toBeNull();
    expect(screen.queryByTestId("banks-list__bulk-bar")).toBeNull();
    // Only BETA was re-probed: 2 pre-checks + 1 re-probe.
    expect(findRun).toHaveBeenCalledTimes(3);
  });
});
