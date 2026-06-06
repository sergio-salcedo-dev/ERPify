import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import type { BankRealtimeHandlers } from "@/context/backoffice/bank/infrastructure/bankRealtime";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { ACME, BETA } from "./_fixtures";

/**
 * Record peek: `o` on a focused row opens a RecordSheet drawer with the five
 * fields already in memory (no fetch) plus a link to the detail; Esc closes
 * it and focus returns to the row. Both roving-focus surfaces (table and
 * stacked list) share the contract.
 */

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const searchRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", async () =>
  (await import("./_mocks")).containerMock({ BackOfficeSearchBanks: { run: searchRun } }),
);

vi.mock("@/context/shared/infrastructure/Notification/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

// Captured so tests can delete the peeked row from under the open drawer.
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

describe("BanksListPage — record peek (`o`)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    realtimeHandlers = undefined;
    searchRun.mockResolvedValue({ banks: [ACME, BETA], nextCursor: undefined });
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

    act(() => realtimeHandlers?.onDeleted?.(ACME.id));

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
