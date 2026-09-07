import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, screen, waitFor } from "@testing-library/react";
import { render } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import type { BankRealtimeHandlers } from "@/context/backoffice/bank/infrastructure/bankRealtime";
import { ACME, searchPage } from "./_fixtures";

/**
 * The list header shows the banks total from the projection read model
 * (`BackOfficeCountBanks`), independent of the page envelope `count` (null under
 * LIGHT pagination). A realtime create/delete refreshes it; a fetch failure is
 * swallowed (the total stays at its default).
 */

const mocks = await vi.hoisted(async () => {
  const { containerMock, routerMock, toastNotifierMock, bankRealtimeMock } =
    await import("./_mocks");
  const spies = { searchRun: vi.fn(), deleteRun: vi.fn(), findRun: vi.fn(), countRun: vi.fn() };
  const realtime: { handlers: BankRealtimeHandlers | undefined } = { handlers: undefined };
  return {
    ...spies,
    realtime,
    navigation: () => routerMock(),
    container: () =>
      containerMock({
        BackOfficeBankCrudRepository: { search: spies.searchRun },
        BackOfficeCountBanks: { run: spies.countRun },
      }),
    toast: () => toastNotifierMock(),
    bankRealtime: () =>
      bankRealtimeMock((handlers) => {
        realtime.handlers = handlers;
      }),
  };
});

vi.mock("next/navigation", mocks.navigation);
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", mocks.container);
vi.mock("@/context/shared/notification/infrastructure/Toast", mocks.toast);
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", mocks.bankRealtime);

const { searchRun, countRun, realtime } = mocks;

describe("BanksListPage — header total", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    realtime.handlers = undefined;
    searchRun.mockResolvedValue(searchPage([ACME]));
  });

  it("renders the total from the count projection", async () => {
    countRun.mockResolvedValue(12);
    render(<BanksListPage />);

    await waitFor(() => {
      expect(screen.getByTestId("banks-list__count")).toHaveTextContent("12 banks total");
    });
  });

  it("uses the singular noun for exactly one bank", async () => {
    countRun.mockResolvedValue(1);
    render(<BanksListPage />);

    await waitFor(() => {
      expect(screen.getByTestId("banks-list__count")).toHaveTextContent("1 bank total");
    });
  });

  it("refreshes the total on a realtime create", async () => {
    countRun.mockResolvedValueOnce(3).mockResolvedValueOnce(4);
    render(<BanksListPage />);
    await waitFor(() => {
      expect(screen.getByTestId("banks-list__count")).toHaveTextContent("3 banks total");
    });

    act(() => realtime.handlers?.onCreated?.(ACME));

    await waitFor(() => {
      expect(screen.getByTestId("banks-list__count")).toHaveTextContent("4 banks total");
    });
  });

  it("states a genuine zero", async () => {
    countRun.mockResolvedValue(0);
    render(<BanksListPage />);

    await waitFor(() => {
      expect(screen.getByTestId("banks-list__count")).toHaveTextContent("0 banks total");
    });
  });

  it("makes no claim about the total when the count read fails (auxiliary read)", async () => {
    countRun.mockRejectedValue(new Error("network"));
    render(<BanksListPage />);

    // The list still renders — the count is auxiliary and must never block it. What it must not do
    // is fall back to a number: "0 banks total" above a populated table is a falsehood stated with
    // the same confidence as a true total, and an unavailable count is not a count of zero.
    await screen.findByTestId(`banks-table__row-${ACME.id}`);
    expect(screen.queryByTestId("banks-list__count")).toBeNull();
  });
});
