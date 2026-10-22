import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import BankAccountsListPage from "@/app/backoffice/bank-accounts/page";
import type { BankAccountRealtimeHandlers } from "@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import { openRowDeleteItem } from "../banks/_interactions";
import { ACME_CHECKING, BETA_RESERVE, resourcePage } from "./_fixtures";

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const search = vi.hoisted(() => vi.fn());
const deleteRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeBankAccountCrudRepository: { search },
    BackOfficeBankAccountResourceNavigator: { follow: vi.fn() },
    BackOfficeDeleteBankAccount: { run: deleteRun },
  }),
);

vi.mock("@/context/shared/notification/infrastructure/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

const realtime = vi.hoisted(() => ({
  handlers: undefined as BankAccountRealtimeHandlers | undefined,
}));
vi.mock("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime", async () =>
  (await import("./_mocks")).bankAccountRealtimeMock((handlers) => {
    realtime.handlers = handlers;
  }),
);

const VIEW_STORAGE_KEY = "erpify:bank-accounts-view";

function problem(type: string, status: number, title: string): ProblemDetails {
  return {
    type,
    title,
    status,
    detail: title,
    instance: `instance-${type}`,
    "correlation-id": `correlation-${type}`,
  };
}

const NOT_FOUND = problem("bank-account-not-found", 404, "Bank account not found");
const NOT_CLOSED = problem("bank-account-not-closed", 409, "Account is not closed");
const SERVER_ERROR = problem("about:blank", 500, "Internal Server Error");

/**
 * The table view mounts the stacked list beside the table (CSS picks one per
 * breakpoint), so every row control exists twice under jsdom; the cards view
 * mounts exactly one per account, which keeps row-scoped testids unambiguous.
 */
async function renderInCardsView(): Promise<void> {
  globalThis.localStorage.setItem(VIEW_STORAGE_KEY, "cards");
  render(<BankAccountsListPage />);
  await screen.findByTestId("bank-accounts-cards");
  await screen.findByTestId(`bank-accounts-cards__item-${BETA_RESERVE.id}`);
}

async function confirmDeleteOf(id: string): Promise<void> {
  fireEvent.click(await openRowDeleteItem("bank-accounts-table", id));
  fireEvent.click(await screen.findByTestId("bank-accounts-row__delete-confirm"));
}

describe("BankAccountsListPage — view preference", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    globalThis.localStorage.clear();
    search.mockResolvedValue(resourcePage([ACME_CHECKING, BETA_RESERVE]));
  });
  afterEach(() => {
    globalThis.localStorage.clear();
  });

  it("restores a stored cards view", async () => {
    await renderInCardsView();

    expect(screen.queryByTestId("bank-accounts-list__table")).toBeNull();
  });

  it("ignores a stored view it does not recognise and keeps the table", async () => {
    globalThis.localStorage.setItem(VIEW_STORAGE_KEY, "mosaic");
    render(<BankAccountsListPage />);

    await screen.findByTestId(`bank-accounts-list__row-${ACME_CHECKING.id}`);
    expect(screen.getByTestId("bank-accounts-list__table")).toBeInTheDocument();
    expect(screen.queryByTestId("bank-accounts-cards")).toBeNull();
  });
});

describe("BankAccountsListPage — sort from the toolbar", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    globalThis.localStorage.clear();
    search.mockResolvedValue(resourcePage([ACME_CHECKING, BETA_RESERVE]));
  });

  it("re-searches with the chosen column, then with no sort once None is picked", async () => {
    await renderInCardsView();

    fireEvent.change(screen.getByTestId("bank-accounts-filters__sort-by"), {
      target: { value: "createdAt" },
    });
    await waitFor(() =>
      expect(search).toHaveBeenLastCalledWith(
        expect.objectContaining({
          sort: { field: "createdAt", direction: SortDirection.ASC },
        }),
      ),
    );

    fireEvent.change(screen.getByTestId("bank-accounts-filters__sort-by"), {
      target: { value: "__none__" },
    });
    await waitFor(() =>
      expect(search).toHaveBeenLastCalledWith(expect.objectContaining({ sort: null })),
    );
  });
});

describe("BankAccountsListPage — delete outcomes", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    globalThis.localStorage.clear();
    realtime.handlers = undefined;
    search.mockResolvedValue(resourcePage([ACME_CHECKING, BETA_RESERVE]));
    deleteRun.mockResolvedValue(undefined);
  });
  afterEach(() => {
    globalThis.localStorage.clear();
  });

  it("names the deleted holder in the toast and moves focus to the list when no row can take it", async () => {
    await renderInCardsView();

    await confirmDeleteOf(BETA_RESERVE.id);

    await waitFor(() =>
      expect(toastNotifier.success).toHaveBeenCalledWith("Account deleted", {
        description: BETA_RESERVE.holderName,
      }),
    );
    await waitFor(() =>
      expect(screen.queryByTestId(`bank-accounts-cards__item-${BETA_RESERVE.id}`)).toBeNull(),
    );
    // The neighbour exists, but the cards view carries neither of the row
    // testids the page focuses, so focus falls back to the list container.
    await waitFor(() => expect(screen.getByTestId("bank-accounts-list")).toHaveFocus());
  });

  it("offers Refresh list for a stale account, which re-searches and clears the error", async () => {
    deleteRun.mockRejectedValue(new HttpError(NOT_FOUND));
    await renderInCardsView();
    expect(search).toHaveBeenCalledTimes(1);

    await confirmDeleteOf(BETA_RESERVE.id);

    const surface = await screen.findByTestId("bank-accounts-list__delete-error");
    expect(surface).toHaveTextContent(NOT_FOUND.title);
    expect(screen.queryByTestId("bank-accounts-list__delete-error-edit")).toBeNull();

    search.mockResolvedValue(resourcePage([ACME_CHECKING]));
    fireEvent.click(within(surface).getByTestId("bank-accounts-list__delete-error-refresh"));

    await waitFor(() => expect(search).toHaveBeenCalledTimes(2));
    await waitFor(() =>
      expect(screen.queryByTestId("bank-accounts-list__delete-error")).toBeNull(),
    );
    await waitFor(() =>
      expect(screen.queryByTestId(`bank-accounts-cards__item-${BETA_RESERVE.id}`)).toBeNull(),
    );
  });

  it("deep-links to the account's edit form when the account is not closed", async () => {
    deleteRun.mockRejectedValue(new HttpError(NOT_CLOSED));
    await renderInCardsView();

    await confirmDeleteOf(BETA_RESERVE.id);

    const surface = await screen.findByTestId("bank-accounts-list__delete-error");
    expect(within(surface).getByTestId("bank-accounts-list__delete-error-edit")).toHaveAttribute(
      "href",
      `/backoffice/bank-accounts/${BETA_RESERVE.id}/edit`,
    );
    expect(screen.queryByTestId("bank-accounts-list__delete-error-refresh")).toBeNull();
  });

  it("drops the edit link once the not-closed account is no longer on the page", async () => {
    deleteRun.mockRejectedValue(new HttpError(NOT_CLOSED));
    await renderInCardsView();
    await confirmDeleteOf(BETA_RESERVE.id);
    await screen.findByTestId("bank-accounts-list__delete-error-edit");

    search.mockResolvedValue(resourcePage([ACME_CHECKING]));
    act(() => {
      realtime.handlers?.onUpdated?.({
        kind: "updated",
        id: BETA_RESERVE.id,
        bankId: BETA_RESERVE.bankId,
      });
    });

    await waitFor(() =>
      expect(screen.queryByTestId(`bank-accounts-cards__item-${BETA_RESERVE.id}`)).toBeNull(),
    );
    // The failure stays on record; only the action pointing at a vanished row goes.
    expect(screen.getByTestId("bank-accounts-list__delete-error")).toBeInTheDocument();
    expect(screen.queryByTestId("bank-accounts-list__delete-error-edit")).toBeNull();
  });

  it("offers no recovery action for an untyped failure", async () => {
    deleteRun.mockRejectedValue(new HttpError(SERVER_ERROR));
    await renderInCardsView();

    await confirmDeleteOf(BETA_RESERVE.id);

    const surface = await screen.findByTestId("bank-accounts-list__delete-error");
    expect(surface).toHaveTextContent(SERVER_ERROR.title);
    expect(screen.queryByTestId("bank-accounts-list__delete-error-refresh")).toBeNull();
    expect(screen.queryByTestId("bank-accounts-list__delete-error-edit")).toBeNull();
  });

  it("dismissing the error removes the persistent surface", async () => {
    deleteRun.mockRejectedValue(new HttpError(SERVER_ERROR));
    await renderInCardsView();
    await confirmDeleteOf(BETA_RESERVE.id);
    await screen.findByTestId("bank-accounts-list__delete-error");

    fireEvent.click(screen.getByTestId("bank-accounts-list__delete-error__dismiss"));

    await waitFor(() =>
      expect(screen.queryByTestId("bank-accounts-list__delete-error")).toBeNull(),
    );
  });
});

describe("BankAccountsListPage — bulk delete confirmation", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    globalThis.localStorage.clear();
    search.mockResolvedValue(resourcePage([ACME_CHECKING, BETA_RESERVE]));
  });
  afterEach(() => {
    globalThis.localStorage.clear();
  });

  it("lists only the selected holders in the confirm dialog", async () => {
    await renderInCardsView();

    fireEvent.click(screen.getByTestId(`bank-accounts-cards__select-${ACME_CHECKING.id}`));
    fireEvent.click(await screen.findByTestId("bank-accounts-list__bulk-delete"));

    const names = await screen.findByTestId("bank-accounts-list__bulk-delete-names");
    expect(names).toHaveTextContent(ACME_CHECKING.holderName);
    expect(names).not.toHaveTextContent(BETA_RESERVE.holderName);
  });
});
