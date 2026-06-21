import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, screen, waitFor } from "@testing-library/react";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { ACME, BETA, searchPage } from "./_fixtures";
import { renderWithRows } from "./_render";

/**
 * Record peek: `o` on a focused row opens a RecordSheet drawer with the five
 * fields already in memory (no fetch) plus a link to the detail; Esc closes
 * it and focus returns to the row. Both roving-focus surfaces (table and
 * stacked list) share the contract.
 */

// `realtime.handlers` lets tests delete the peeked row from under the open drawer.
const mocks = await vi.hoisted(async () => (await import("./_mocks")).banksListPageMocks());

vi.mock("next/navigation", mocks.navigation);
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", mocks.container);
vi.mock("@/context/shared/notification/infrastructure/Toast", mocks.toast);
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", mocks.bankRealtime);

const { searchRun, realtime } = mocks;

describe("BanksListPage — record peek (`o`)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    realtime.handlers = undefined;
    searchRun.mockResolvedValue(searchPage([ACME, BETA]));
  });

  it("opens the peek with the five in-memory fields and a detail link from a table row", async () => {
    await renderWithRows();

    const row = screen.getByTestId(`banks-table__row-${ACME.id}`);
    row.focus();
    fireEvent.keyDown(row, { key: "o" });

    const peek = await screen.findByTestId("banks-list__peek");
    expect(peek).toBeInTheDocument();
    // No fetch: the drawer renders straight from the already-loaded row.
    expect(searchRun).toHaveBeenCalledTimes(1);

    expect(screen.getByTestId("banks-peek__id")).toHaveTextContent(ACME.id);
    expect(screen.getByTestId("banks-peek__name")).toHaveTextContent(ACME.name);
    expect(screen.getByTestId("banks-peek__shortname")).toHaveTextContent(ACME.shortName);
    expect(screen.getByTestId("banks-peek__created-at")).toHaveTextContent(
      dateTimeProvider.formatIsoToLocalDateTime(ACME.createdAt),
    );
    expect(screen.getByTestId("banks-peek__updated-at")).toHaveTextContent(
      dateTimeProvider.formatIsoToLocalDateTime(ACME.updatedAt),
    );
    expect(screen.getByTestId("banks-peek__detail-link")).toHaveAttribute(
      "href",
      `/backoffice/banks/${ACME.id}`,
    );
  });

  it("closes on Esc and returns focus to the row that opened it", async () => {
    await renderWithRows();

    const row = screen.getByTestId(`banks-table__row-${ACME.id}`);
    row.focus();
    fireEvent.keyDown(row, { key: "o" });
    const peek = await screen.findByTestId("banks-list__peek");

    fireEvent.keyDown(peek, { key: "Escape" });

    await waitFor(() => {
      expect(screen.queryByTestId("banks-list__peek")).toBeNull();
    });
    await waitFor(() => {
      expect(row).toHaveFocus();
    });
  });

  it("closes the peek when the peeked row is deleted remotely, landing focus on the list", async () => {
    await renderWithRows();

    const row = screen.getByTestId(`banks-table__row-${ACME.id}`);
    row.focus();
    fireEvent.keyDown(row, { key: "o" });
    await screen.findByTestId("banks-list__peek");

    // The remote delete reconciles via a silent refetch (server-driven), so the
    // server now lists everything except ACME.
    searchRun.mockResolvedValue(searchPage([BETA]));
    act(() => realtime.handlers?.onDeleted?.(ACME.id));

    await waitFor(() => {
      expect(screen.queryByTestId("banks-list__peek")).toBeNull();
    });
    // The opening row is gone — focus must not strand on <body>.
    await waitFor(() => {
      expect(screen.getByTestId("banks-list")).toHaveFocus();
    });
  });

  it("opens the same peek from a stacked-list row", async () => {
    await renderWithRows();

    const row = screen.getByTestId(`banks-stacked__row-${BETA.id}`);
    // Focusing a non-zero row moves the roving tab stop (a state update).
    act(() => row.focus());
    fireEvent.keyDown(row, { key: "o" });

    await screen.findByTestId("banks-list__peek");
    expect(screen.getByTestId("banks-peek__name")).toHaveTextContent(BETA.name);
  });
});
