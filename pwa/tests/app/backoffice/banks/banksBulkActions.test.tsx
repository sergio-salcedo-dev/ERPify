import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";

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

describe("BanksListPage — bulk actions", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    searchRun.mockResolvedValue({ banks: [ACME, BETA], nextCursor: undefined });
    deleteRun.mockResolvedValue(undefined);
  });

  it("reveals a bulk bar with the selection count once rows are selected", async () => {
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    expect(screen.queryByTestId("banks-list__bulk-bar")).toBeNull();

    fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));

    expect(await screen.findByTestId("banks-list__bulk-bar")).toBeInTheDocument();
    expect(screen.getByTestId("banks-list__bulk-count")).toHaveTextContent("1 selected");
  });

  it("bulk-deletes every selected bank and clears the selection", async () => {
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));
    fireEvent.click(screen.getByLabelText(`Select row ${BETA.id}`));
    expect(screen.getByTestId("banks-list__bulk-count")).toHaveTextContent("2 selected");

    fireEvent.click(screen.getByTestId("banks-list__bulk-delete"));
    fireEvent.click(await screen.findByTestId("banks-list__bulk-delete-confirm"));

    await waitFor(() => {
      expect(deleteRun).toHaveBeenCalledWith(ACME.id);
      expect(deleteRun).toHaveBeenCalledWith(BETA.id);
    });
    await waitFor(() => {
      expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
      expect(screen.queryByTestId(`banks-table__row-${BETA.id}`)).toBeNull();
    });
    expect(screen.queryByTestId("banks-list__bulk-bar")).toBeNull();
  });

  it("re-adds rows that fail to delete and reports the failure (optimistic rollback)", async () => {
    deleteRun.mockImplementation((id: string) => {
      if (id === BETA.id) return Promise.reject(new Error("boom"));
      return Promise.resolve(undefined);
    });
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));
    fireEvent.click(screen.getByLabelText(`Select row ${BETA.id}`));
    fireEvent.click(screen.getByTestId("banks-list__bulk-delete"));
    fireEvent.click(await screen.findByTestId("banks-list__bulk-delete-confirm"));

    // ACME succeeds and stays gone; BETA fails and is restored.
    await waitFor(() => {
      expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    });
    expect(await screen.findByTestId(`banks-table__row-${BETA.id}`)).toBeInTheDocument();
    expect(toastNotifier.error).toHaveBeenCalled();
  });

  it("clears the selection via Clear without deleting anything", async () => {
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));
    fireEvent.click(await screen.findByTestId("banks-list__bulk-clear"));

    await waitFor(() => expect(screen.queryByTestId("banks-list__bulk-bar")).toBeNull());
    expect(deleteRun).not.toHaveBeenCalled();
  });

  it("floats the bulk bar at the bottom of the content column while a selection exists", async () => {
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);
    fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));

    const bar = await screen.findByTestId("banks-list__bulk-bar");
    expect(bar.className).toContain("sticky");
    expect(bar.className).toContain("bottom-6");
    expect(bar.className).toContain("mx-auto");

    const table = screen.getByTestId(`banks-table__row-${ACME.id}`).closest("table");
    expect(
      table && bar.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_PRECEDING,
    ).toBeTruthy();

    fireEvent.click(screen.getByTestId("banks-list__bulk-clear"));
    await waitFor(() => {
      expect(screen.queryByTestId("banks-list__bulk-bar")).not.toBeInTheDocument();
    });
  });
});
