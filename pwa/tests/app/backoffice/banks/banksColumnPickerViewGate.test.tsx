import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, screen } from "@testing-library/react";
import { ACME, BETA, searchPage } from "./_fixtures";
import { renderWithRows } from "./_render";

/**
 * The column picker is a table-only affordance: the cards render a fixed
 * curated layout that never reads the column preference, so the picker is
 * gated behind table view. Column state lives in page-level storage, so
 * hiding it in cards is non-destructive — it returns on switching back.
 */

const mocks = await vi.hoisted(async () => (await import("./_mocks")).banksListPageMocks());

vi.mock("next/navigation", mocks.navigation);
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", mocks.container);
vi.mock("@/context/shared/notification/infrastructure/Toast", mocks.toast);
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", mocks.bankRealtime);

const { searchRun } = mocks;

describe("BanksListPage — column picker view gate", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // The view toggle persists to localStorage; clear it so each test starts
    // from the default table view instead of a prior test's stored choice.
    localStorage.clear();
    searchRun.mockResolvedValue(searchPage([ACME, BETA]));
  });

  it("shows the column picker in table view and hides it in cards view", async () => {
    await renderWithRows();

    // Table is the default view — the picker is available.
    expect(screen.getByTestId("banks-list__columns")).toBeInTheDocument();

    fireEvent.click(screen.getByTestId("banks-list__view-toggle-cards"));

    // Cards render a fixed layout — the picker is gone; the sibling display
    // toggles stay put.
    await screen.findByTestId("banks-cards");
    expect(screen.queryByTestId("banks-list__columns")).toBeNull();
    expect(screen.getByTestId("banks-list__density-toggle")).toBeInTheDocument();
    expect(screen.getByTestId("banks-list__view-toggle-table")).toBeInTheDocument();
  });

  it("brings the picker back when switching from cards to table", async () => {
    await renderWithRows();

    fireEvent.click(screen.getByTestId("banks-list__view-toggle-cards"));
    await screen.findByTestId("banks-cards");
    expect(screen.queryByTestId("banks-list__columns")).toBeNull();

    fireEvent.click(screen.getByTestId("banks-list__view-toggle-table"));

    // Back in table view the picker re-mounts, reading the same persisted
    // column selection it always did.
    expect(await screen.findByTestId("banks-list__columns")).toBeInTheDocument();
  });
});
