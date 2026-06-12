import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, screen, waitFor } from "@testing-library/react";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import { BANKS_PAGE_SIZE_DEFAULT } from "@/app/backoffice/banks/_lib/paginate";
import { ACME, BETA, searchPage } from "./_fixtures";
import { renderWithRows } from "./_render";

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const { searchRun, follow } = vi.hoisted(() => ({ searchRun: vi.fn(), follow: vi.fn() }));
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeSearchBanks: { run: searchRun },
    BackOfficeBankSearchNavigator: { follow },
  }),
);

vi.mock("@/context/shared/infrastructure/Notification/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

// Neutralise the Mercure subscription: unmocked, useBankRealtime spins up
// fetch/EventSource churn that flakes under parallel CI load.
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", async () =>
  (await import("./_mocks")).bankRealtimeMock(),
);

const NEXT_LINK = "/api/back_office/banks?after=opaque-cursor&limit=25";
const PREV_LINK = "/api/back_office/banks?before=opaque-cursor&limit=25";

/**
 * Shared entry point for both cases: land on page 1, follow the server-issued
 * `next` link, and prove the paginated request rode the link (cursor) through
 * the navigator — never the criteria-search path.
 */
async function paginateForward(): Promise<void> {
  searchRun.mockResolvedValue(
    searchPage([ACME], { hasNext: true, links: { next: NEXT_LINK, prev: null } }),
  );
  follow.mockResolvedValue(
    searchPage([BETA], { hasPrev: true, links: { next: null, prev: PREV_LINK } }),
  );

  await renderWithRows();
  expect(searchRun).toHaveBeenCalledTimes(1);

  fireEvent.click(screen.getByRole("button", { name: "Next page" }));
  await screen.findByTestId(`banks-table__row-${BETA.id}`);

  expect(follow).toHaveBeenCalledTimes(1);
  expect(follow).toHaveBeenCalledWith(NEXT_LINK);
  // The cursor travelled inside the link: no second criteria search fired.
  expect(searchRun).toHaveBeenCalledTimes(1);
}

describe("BanksListPage — query change discards the active cursor (W8)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("a sort change drops the cursor: the next request is a criteria search, not a link replay", async () => {
    await paginateForward();

    fireEvent.click(screen.getByRole("button", { name: "Filters" }));
    fireEvent.change(screen.getByRole("combobox", { name: "Sort by" }), {
      target: { value: "createdAt" },
    });

    await waitFor(() => expect(searchRun).toHaveBeenCalledTimes(2));
    // The stale link is never replayed under the new sort.
    expect(follow).toHaveBeenCalledTimes(1);
    // Exact-match criteria: filters/sort/limit only — no after/before survives.
    expect(searchRun).toHaveBeenLastCalledWith({
      filters: [],
      sort: { field: "createdAt", direction: SortDirection.ASC },
      limit: BANKS_PAGE_SIZE_DEFAULT,
    });
  });

  it("a filter change drops the cursor: the debounced search goes out without after/before", async () => {
    await paginateForward();

    fireEvent.change(screen.getByRole("searchbox", { name: "Search banks by name" }), {
      target: { value: "acme" },
    });

    // The name filter debounces ~300ms before committing; waitFor polls it out.
    await waitFor(() => expect(searchRun).toHaveBeenCalledTimes(2));
    expect(follow).toHaveBeenCalledTimes(1);
    expect(searchRun).toHaveBeenLastCalledWith({
      filters: [{ field: "name", operator: "contains", value: "acme" }],
      sort: { field: "name", direction: SortDirection.ASC },
      limit: BANKS_PAGE_SIZE_DEFAULT,
    });
  });
});
