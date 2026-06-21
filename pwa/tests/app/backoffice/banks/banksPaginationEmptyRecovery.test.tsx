import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { ACME, BETA, searchPage } from "./_fixtures";
import { confirmDeleteOf } from "./_interactions";

/** A row living on the previous page — distinct from the deleted ACME/BETA. */
const GAMMA = Bank.fromPrimitives({
  id: "33333333-3333-4333-8333-333333333333",
  name: "Gamma Credit",
  shortName: "GAMMA",
  createdAt: "2026-01-03T10:00:00Z",
  updatedAt: "2026-04-17T14:30:00Z",
  accountCount: 0,
});

/**
 * Deleting the last row(s) of a page BEYOND the first must not strand the user.
 * A keyset page that empties out still carries a backward affordance
 * (`hasPrev` + `links.prev`); the list auto-follows it so the user lands back on
 * the previous page's real rows instead of the misleading "No banks match your
 * filters" panel (whose only escape — Clear all — is a no-op when no filter is
 * even active).
 */

const PREV_LINK = "/api/banks?before=cursor-page-1";

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const searchRun = vi.hoisted(() => vi.fn());
const deleteRun = vi.hoisted(() => vi.fn());
const findRun = vi.hoisted(() => vi.fn());
const followRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeSearchBanks: { run: searchRun },
    BackOfficeDeleteBank: { run: deleteRun },
    BackOfficeFindBank: { run: findRun },
    BackOfficeBankSearchNavigator: { follow: followRun },
  }),
);

vi.mock("@/context/shared/Notification/infrastructure/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

// Neutralise the live Mercure subscription — these specs drive no realtime
// events and the live hook's fetch/EventSource churn flakes under parallel load.
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", async () =>
  (await import("./_mocks")).bankRealtimeMock(),
);

describe("BanksListPage — emptied page recovers to the previous page", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    deleteRun.mockResolvedValue(undefined);
    findRun.mockImplementation((id: string) => Promise.resolve(id === ACME.id ? ACME : BETA));
    // The previous page the backward affordance points at — page-1 rows (GAMMA),
    // with no further backward affordance.
    followRun.mockResolvedValue(
      searchPage([GAMMA], { hasPrev: false, links: { prev: null, next: null } }),
    );
  });

  it("auto-follows links.prev when the last row of a non-first page is deleted", async () => {
    // We are on the last page: a single remaining row, a backward affordance to
    // page 1, no forward affordance.
    searchRun.mockResolvedValue(
      searchPage([ACME], { hasPrev: true, links: { prev: PREV_LINK, next: null } }),
    );
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    await confirmDeleteOf(ACME.id);

    // The list walks back to the previous page rather than showing an empty panel.
    await waitFor(() => {
      expect(followRun).toHaveBeenCalledWith(PREV_LINK);
    });
    expect(await screen.findByTestId(`banks-table__row-${GAMMA.id}`)).toBeInTheDocument();
    // The misleading filtered-to-zero panel is never shown — no filter is active.
    expect(screen.queryByTestId("banks-list__empty-filtered")).toBeNull();
  });

  it("auto-follows links.prev when a bulk delete empties a non-first page", async () => {
    // The last page holds two rows, both selected and deleted at once.
    searchRun.mockResolvedValue(
      searchPage([ACME, BETA], { hasPrev: true, links: { prev: PREV_LINK, next: null } }),
    );
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));
    fireEvent.click(screen.getByLabelText(`Select row ${BETA.id}`));
    fireEvent.click(screen.getByTestId("banks-list__bulk-delete"));
    fireEvent.click(await screen.findByTestId("banks-list__bulk-delete-confirm"));

    await waitFor(() => {
      expect(followRun).toHaveBeenCalledWith(PREV_LINK);
    });
    expect(await screen.findByTestId(`banks-table__row-${GAMMA.id}`)).toBeInTheDocument();
    expect(screen.queryByTestId("banks-list__empty-filtered")).toBeNull();
  });
});
