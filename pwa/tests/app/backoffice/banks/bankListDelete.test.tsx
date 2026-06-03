import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";

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

const ACME = Bank.fromPrimitives({
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-04-15T14:30:00Z",
});
const BETA = Bank.fromPrimitives({
  id: "22222222-2222-4222-8222-222222222222",
  name: "Beta Bank",
  shortName: "BETA",
  createdAt: "2026-01-02T10:00:00Z",
  updatedAt: "2026-04-16T14:30:00Z",
});

const push = vi.fn();
const refresh = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, refresh, back: vi.fn() }),
}));

const searchRun = vi.fn();
const deleteRun = vi.fn();

vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeSearchBanks") return { run: searchRun };
      if (token === "BackOfficeDeleteBank") return { run: deleteRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

vi.mock("@/context/shared/infrastructure/Notification/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

// Neutralise the Mercure subscription: these tests exercise local deletes, and
// the live hook's fetch/EventSource churn otherwise flakes under parallel load.
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", () => ({
  bankTopics: { collection: "urn:erpify:backoffice:banks" },
  useBankRealtime: vi.fn(),
}));

describe("BanksListPage — delete UX", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    searchRun.mockResolvedValue({ banks: [ACME, BETA], nextCursor: undefined });
    deleteRun.mockResolvedValue(undefined);
  });

  it("shows a success toast and removes the row after a successful delete", async () => {
    render(<BanksListPage />);
    expect(await screen.findByTestId(`banks-table__row-${ACME.id}`)).toBeInTheDocument();

    fireEvent.click(screen.getByTestId(`banks-table__actions-${ACME.id}`));
    fireEvent.click(await screen.findByTestId(`banks-table__delete-${ACME.id}`));
    fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));

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

    fireEvent.click(screen.getByTestId(`banks-table__actions-${ACME.id}`));
    fireEvent.click(await screen.findByTestId(`banks-table__delete-${ACME.id}`));
    fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));

    await waitFor(() => {
      expect(deleteRun).toHaveBeenCalledWith(ACME.id);
    });
    // The dialog's Confirm button bubbles to the row's onClick through the
    // React portal; the row must NOT treat it as an activation and push the
    // detail route for the bank we just removed.
    expect(push).not.toHaveBeenCalledWith(`/backoffice/banks/${ACME.id}`);
  });

  it("shows the first-run empty state, not the filtered-to-zero state, after deleting the last bank", async () => {
    searchRun.mockResolvedValue({ banks: [ACME], nextCursor: undefined });
    render(<BanksListPage />);
    expect(await screen.findByTestId(`banks-table__row-${ACME.id}`)).toBeInTheDocument();

    fireEvent.click(screen.getByTestId(`banks-table__actions-${ACME.id}`));
    fireEvent.click(await screen.findByTestId(`banks-table__delete-${ACME.id}`));
    fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));

    // No banks remain and no filter is active, so the list must read as the
    // first-run empty state — never the "No banks match your filters" panel,
    // which only makes sense while banks exist behind an active filter.
    expect(await screen.findByRole("heading", { name: "No banks yet" })).toBeInTheDocument();
    expect(screen.queryByTestId("banks-list__empty-filtered")).toBeNull();
  });
});
