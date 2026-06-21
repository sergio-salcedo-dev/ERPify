import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, screen, waitFor } from "@testing-library/react";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { ACME, BETA, searchPage } from "./_fixtures";
import { renderWithRows } from "./_render";

/**
 * Under server-driven search a Mercure event reconciles by silently refetching
 * the current page — the server list is authoritative, so there is no in-memory
 * merge to "resurrect". The deleted-id tombstone now covers a single window: a
 * Mercure `deleted` processed during the bulk-restore re-probe (where the
 * reconciling refetch is suppressed so the optimistic bulk op owns the page).
 */

// The Mercure subscription is replaced while `realtime.handlers` captures the
// handlers, so tests can drive realtime deltas directly.
const mocks = await vi.hoisted(async () => (await import("./_mocks")).banksListPageMocks());

vi.mock("next/navigation", mocks.navigation);
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", mocks.container);
vi.mock("@/context/shared/notification/infrastructure/Toast", mocks.toast);
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", mocks.bankRealtime);

const { searchRun, deleteRun, findRun, realtime } = mocks;

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
    realtime.handlers = undefined;
    searchRun.mockResolvedValue(searchPage([ACME, BETA]));
    deleteRun.mockResolvedValue(undefined);
    findRun.mockImplementation((id: string) => Promise.resolve(id === ACME.id ? ACME : BETA));
  });

  it("reconciles a remote delete via a silent refetch — the server list wins", async () => {
    await renderWithRows();
    expect(searchRun).toHaveBeenCalledTimes(1);

    // The server now lists everything except ACME; the Mercure `deleted` triggers
    // a silent refetch that reflects it (no in-memory merge / resurrection).
    searchRun.mockResolvedValue(searchPage([BETA]));
    act(() => realtime.handlers?.onDeleted?.(ACME.id));

    await waitFor(() => {
      expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    });
    expect(screen.getByTestId(`banks-table__row-${BETA.id}`)).toBeInTheDocument();
    expect(searchRun).toHaveBeenCalledTimes(2);
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
    act(() => realtime.handlers?.onDeleted?.(BETA.id));
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
