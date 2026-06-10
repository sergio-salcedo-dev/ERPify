import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import type { BankRealtimeHandlers } from "@/context/backoffice/bank/infrastructure/bankRealtime";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import { ACME, BETA, searchPage } from "./_fixtures";

/**
 * Counterpart to `bankDetailDelete.test.tsx`: deleting a bank from the LIST
 * must surface a success toast and keep the user on the list. The delete
 * dialog's trigger lives inside a clickable `<DataTable>` row, and the dialog
 * content renders through a React portal — clicks on the dialog's
 * Confirm/Cancel buttons still bubble to the row's `onClick` via the React
 * tree, so a regression here re-activated the row and navigated to the
 * just-deleted bank's detail page (HTTP 404). These tests lock both halves:
 * the toast fires AND no detail navigation happens.
 */

const push = vi.hoisted(() => vi.fn());
const refresh = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock({ push, refresh }));

const searchRun = vi.hoisted(() => vi.fn());
const deleteRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeSearchBanks: { run: searchRun },
    BackOfficeDeleteBank: { run: deleteRun },
  }),
);

vi.mock("@/context/shared/infrastructure/Notification/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

// Replace the Mercure subscription (the live hook's fetch/EventSource churn
// flakes under parallel load) while capturing the handlers, so tests can
// drive realtime deltas directly.
let realtimeHandlers: BankRealtimeHandlers | undefined;
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", async () =>
  (await import("./_mocks")).bankRealtimeMock((handlers) => {
    realtimeHandlers = handlers;
  }),
);

describe("BanksListPage — delete UX", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    searchRun.mockResolvedValue(searchPage([ACME, BETA]));
    deleteRun.mockResolvedValue(undefined);
  });

  it("shows a success toast and removes the row after a successful delete", async () => {
    render(<BanksListPage />);
    expect(await screen.findByTestId(`banks-table__row-${ACME.id}`)).toBeInTheDocument();

    await confirmDeleteOf(ACME.id);

    await waitFor(() => {
      expect(toastNotifier.success).toHaveBeenCalledWith("Bank deleted", {
        description: "Acme Savings",
      });
    });
    expect(deleteRun).toHaveBeenCalledWith(ACME.id);
    await waitFor(() => {
      expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    });
    expect(screen.getByTestId(`banks-table__row-${BETA.id}`)).toBeInTheDocument();
  });

  it("never navigates to the deleted bank's detail page (no row activation through the dialog portal)", async () => {
    render(<BanksListPage />);
    expect(await screen.findByTestId(`banks-table__row-${ACME.id}`)).toBeInTheDocument();

    await confirmDeleteOf(ACME.id);

    await waitFor(() => {
      expect(deleteRun).toHaveBeenCalledWith(ACME.id);
    });
    // The dialog's Confirm button bubbles to the row's onClick through the
    // React portal; the row must NOT treat it as an activation and push the
    // detail route for the bank we just removed.
    expect(push).not.toHaveBeenCalledWith(`/backoffice/banks/${ACME.id}`);
  });

  it("shows the first-run empty state, not the filtered-to-zero state, after deleting the last bank", async () => {
    searchRun.mockResolvedValue(searchPage([ACME]));
    render(<BanksListPage />);
    expect(await screen.findByTestId(`banks-table__row-${ACME.id}`)).toBeInTheDocument();

    await confirmDeleteOf(ACME.id);

    // No banks remain and no filter is active, so the list must read as the
    // first-run empty state — never the "No banks match your filters" panel,
    // which only makes sense while banks exist behind an active filter.
    expect(await screen.findByRole("heading", { name: "No banks yet" })).toBeInTheDocument();
    expect(screen.queryByTestId("banks-list__empty-filtered")).toBeNull();
  });
});

const IN_USE_PROBLEM: ProblemDetails = {
  type: "bank-in-use",
  title: "Bank cannot be deleted: 3 associated bank accounts",
  status: 409,
  detail: "Remove or reassign the associated bank accounts first.",
  instance: "01926e7e-7b8a-7c4e-9f31-000000000409",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000409",
  bankId: ACME.id,
  accountCount: 3,
};

const STALE_PROBLEM: ProblemDetails = {
  type: "bank-not-found",
  title: "Bank not found",
  status: 404,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000404",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000404",
};

// The row menu is a Radix dropdown rendered through a portal: under jsdom
// churn a just-opened menu can close again before its items render, so the
// OPEN is retried (never the assertions — those stay single-shot).
async function openDeleteItem(id: string): Promise<HTMLElement> {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    fireEvent.click(screen.getByTestId(`banks-table__actions-${id}`));
    try {
      return await screen.findByTestId(`banks-table__delete-${id}`);
    } catch {
      // The item never rendered — the open lost the race; re-open the menu.
    }
  }
  fireEvent.click(screen.getByTestId(`banks-table__actions-${id}`));
  return screen.findByTestId(`banks-table__delete-${id}`);
}

async function confirmDeleteOf(id: string): Promise<void> {
  fireEvent.click(await openDeleteItem(id));
  fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));
}

describe("BanksListPage — failed delete lands in the persistent error surface", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    searchRun.mockResolvedValue(searchPage([ACME, BETA]));
  });

  it("409 bank-in-use: the dialog closes, the error persists above the list with no recovery action, the row stays", async () => {
    deleteRun.mockRejectedValue(new HttpError(IN_USE_PROBLEM));
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    await confirmDeleteOf(ACME.id);

    const surface = await screen.findByTestId("banks-list__delete-error");
    // Verbatim problem, never inside the dialog.
    expect(screen.queryByTestId("banks-detail__delete-dialog")).toBeNull();
    expect(surface).toHaveTextContent(IN_USE_PROBLEM.title);
    expect(screen.getByTestId("problem-display__type")).toHaveTextContent("bank-in-use");
    // 409 carries no recovery action — recovery lives outside the list.
    expect(screen.queryByTestId("banks-list__delete-error-refresh")).toBeNull();
    // Copy affordances are present; the row was never removed.
    expect(screen.getByTestId("banks-list__delete-error__copy-json")).toBeInTheDocument();
    expect(screen.getByTestId(`banks-table__row-${ACME.id}`)).toBeInTheDocument();
    // The toast is only a transient pointer to the persistent surface.
    expect(toastNotifier.error).toHaveBeenCalledWith("Couldn't delete bank — see error details");
    // The surface owns focus so the closing dialog never strands it on <body>.
    expect(surface).toHaveFocus();
  });

  it("404 stale row: Refresh list clears the error, drops the row, and focuses its neighbor", async () => {
    deleteRun.mockRejectedValue(new HttpError(STALE_PROBLEM));
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    await confirmDeleteOf(ACME.id);

    await screen.findByTestId("banks-list__delete-error");
    searchRun.mockResolvedValue(searchPage([BETA]));

    fireEvent.click(screen.getByTestId("banks-list__delete-error-refresh"));

    await waitFor(() => {
      expect(screen.queryByTestId("banks-list__delete-error")).toBeNull();
    });
    await waitFor(() => {
      expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    });
    // jsdom renders both responsive surfaces (CSS hiding doesn't apply), and
    // the focus seam targets the first match in document order — the stacked
    // row. Either surface's row counts as the neighbor receiving focus.
    await waitFor(() => {
      const active = document.activeElement;
      const focused = active instanceof HTMLElement ? active.dataset.testid : undefined;
      expect([`banks-table__row-${BETA.id}`, `banks-stacked__row-${BETA.id}`]).toContain(focused);
    });
    // A single-row refresh changes no selection count, so the outcome is
    // announced through the dedicated polite region.
    await waitFor(() => {
      expect(screen.getByTestId("banks-list__refresh-status")).toHaveTextContent("List refreshed");
    });
  });

  it("survives realtime deltas and silent refetches — only dismiss/retry/success close it locally", async () => {
    deleteRun.mockRejectedValue(new HttpError(IN_USE_PROBLEM));
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    await confirmDeleteOf(ACME.id);
    await screen.findByTestId("banks-list__delete-error");

    // A Mercure delta reconciles the list (another client deleted BETA): the
    // silent refetch returns the list without BETA.
    searchRun.mockResolvedValue(searchPage([ACME]));
    act(() => {
      realtimeHandlers?.onDeleted?.(BETA.id);
    });
    await waitFor(() => {
      expect(screen.queryByTestId(`banks-table__row-${BETA.id}`)).toBeNull();
    });
    // …and the persistent error is still exactly where the user left it.
    expect(screen.getByTestId("banks-list__delete-error")).toBeInTheDocument();
    expect(screen.getByTestId("problem-display__type")).toHaveTextContent("bank-in-use");
  });

  it("dismisses with × and is replaced (not stacked) by the next attempt's failure", async () => {
    deleteRun.mockRejectedValue(new HttpError(IN_USE_PROBLEM));
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    await confirmDeleteOf(ACME.id);
    await screen.findByTestId("banks-list__delete-error");

    fireEvent.click(screen.getByTestId("banks-list__delete-error__dismiss"));
    expect(screen.queryByTestId("banks-list__delete-error")).toBeNull();

    deleteRun.mockRejectedValue(new HttpError(STALE_PROBLEM));
    await confirmDeleteOf(BETA.id);

    const surfaces = await screen.findAllByTestId("banks-list__delete-error");
    expect(surfaces).toHaveLength(1);
    expect(surfaces[0]).toHaveTextContent(STALE_PROBLEM.title);
  });
});
