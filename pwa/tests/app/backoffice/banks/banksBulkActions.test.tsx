import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
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
const findRun = vi.fn();
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeSearchBanks") return { run: searchRun };
      if (token === "BackOfficeDeleteBank") return { run: deleteRun };
      if (token === "BackOfficeFindBank") return { run: findRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

vi.mock("@/context/shared/infrastructure/Notification/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

const STALE_PROBLEM: ProblemDetails = {
  type: "bank-not-found",
  title: "Bank not found",
  status: 404,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000404",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000404",
};

describe("BanksListPage — bulk actions", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    searchRun.mockResolvedValue({ banks: [ACME, BETA], nextCursor: undefined });
    deleteRun.mockResolvedValue(undefined);
    // The existence pre-check probes every selected id before the attempt.
    findRun.mockImplementation((id: string) => Promise.resolve(id === ACME.id ? ACME : BETA));
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

  it("re-adds rows AND selection for non-404 failures, anchoring the problem in the persistent surface", async () => {
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
    // The selection of the failed row is restored too — retry without re-picking.
    expect(await screen.findByTestId("banks-list__bulk-count")).toHaveTextContent("1 selected");
    // The failure lives in the persistent surface; the toast only points at it.
    expect(await screen.findByTestId("banks-list__delete-error")).toBeInTheDocument();
    expect(toastNotifier.error).toHaveBeenCalledWith("Some banks could not be deleted", {
      description: "1 of 2 could not be deleted. See error details.",
    });
  });

  it("404 rejections do not resurrect the row (the bank is already gone)", async () => {
    deleteRun.mockImplementation((id: string) => {
      if (id === BETA.id) return Promise.reject(new HttpError(STALE_PROBLEM));
      return Promise.resolve(undefined);
    });
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));
    fireEvent.click(screen.getByLabelText(`Select row ${BETA.id}`));
    fireEvent.click(screen.getByTestId("banks-list__bulk-delete"));
    fireEvent.click(await screen.findByTestId("banks-list__bulk-delete-confirm"));

    await screen.findByTestId("banks-list__delete-error");
    expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    expect(screen.queryByTestId(`banks-table__row-${BETA.id}`)).toBeNull();
    expect(screen.queryByTestId("banks-list__bulk-bar")).toBeNull();
  });

  it("does not resurrect a row whose re-probe 404s (deleted remotely mid-flight)", async () => {
    deleteRun.mockImplementation((id: string) => {
      if (id === BETA.id) return Promise.reject(new Error("boom"));
      return Promise.resolve(undefined);
    });
    // Pre-check (1st call per id) resolves; the re-probe (2nd call) for BETA
    // 404s — another client deleted it during the probe/delete window.
    const findCalls = new Map<string, number>();
    findRun.mockImplementation((id: string) => {
      const count = (findCalls.get(id) ?? 0) + 1;
      findCalls.set(id, count);
      if (id === BETA.id && count >= 2) return Promise.reject(new HttpError(STALE_PROBLEM));
      return Promise.resolve(id === ACME.id ? ACME : BETA);
    });
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));
    fireEvent.click(screen.getByLabelText(`Select row ${BETA.id}`));
    fireEvent.click(screen.getByTestId("banks-list__bulk-delete"));
    fireEvent.click(await screen.findByTestId("banks-list__bulk-delete-confirm"));

    // The error surface still reports the failure.
    await screen.findByTestId("banks-list__delete-error");
    // ACME deleted; BETA confirmed gone by the re-probe — not resurrected.
    expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    expect(screen.queryByTestId(`banks-table__row-${BETA.id}`)).toBeNull();
    // Selection was not re-added, so no bulk bar.
    expect(screen.queryByTestId("banks-list__bulk-bar")).toBeNull();
    expect(toastNotifier.error).toHaveBeenCalledWith("Some banks could not be deleted", {
      description: "1 of 2 could not be deleted. See error details.",
    });
    // Only BETA was re-probed: 2 pre-checks + 1 re-probe — a successful
    // delete (ACME) never triggers one.
    expect(findRun).toHaveBeenCalledTimes(3);
  });

  it("fails open to the snapshot restore when the re-probe errors with something other than 404", async () => {
    deleteRun.mockImplementation((id: string) => {
      if (id === BETA.id) return Promise.reject(new Error("boom"));
      return Promise.resolve(undefined);
    });
    // Pre-check resolves; the re-probe for BETA errors with a non-404 — fail
    // open to the snapshot restore.
    const findCalls = new Map<string, number>();
    findRun.mockImplementation((id: string) => {
      const count = (findCalls.get(id) ?? 0) + 1;
      findCalls.set(id, count);
      if (id === BETA.id && count >= 2) return Promise.reject(new Error("probe blip"));
      return Promise.resolve(id === ACME.id ? ACME : BETA);
    });
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));
    fireEvent.click(screen.getByLabelText(`Select row ${BETA.id}`));
    fireEvent.click(screen.getByTestId("banks-list__bulk-delete"));
    fireEvent.click(await screen.findByTestId("banks-list__bulk-delete-confirm"));

    // ACME stays gone; BETA is restored from the snapshot (fail-open).
    await waitFor(() => {
      expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    });
    expect(await screen.findByTestId(`banks-table__row-${BETA.id}`)).toBeInTheDocument();
    expect(await screen.findByTestId("banks-list__bulk-count")).toHaveTextContent("1 selected");
    // Only BETA was re-probed: 2 pre-checks + 1 re-probe.
    expect(findRun).toHaveBeenCalledTimes(3);
  });

  it("aborts the whole bulk when the pre-check finds a stale id: nothing is deleted, Refresh recalculates", async () => {
    findRun.mockImplementation((id: string) => {
      if (id === BETA.id) return Promise.reject(new HttpError(STALE_PROBLEM));
      return Promise.resolve(ACME);
    });
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));
    fireEvent.click(screen.getByLabelText(`Select row ${BETA.id}`));
    fireEvent.click(screen.getByTestId("banks-list__bulk-delete"));
    fireEvent.click(await screen.findByTestId("banks-list__bulk-delete-confirm"));

    const surface = await screen.findByTestId("banks-list__delete-error");
    expect(surface).toHaveTextContent(STALE_PROBLEM.title);
    expect(deleteRun).not.toHaveBeenCalled();
    // Both rows are still there — nothing mutated.
    expect(screen.getByTestId(`banks-table__row-${ACME.id}`)).toBeInTheDocument();
    expect(screen.getByTestId(`banks-table__row-${BETA.id}`)).toBeInTheDocument();
    expect(toastNotifier.error).toHaveBeenCalledWith("Couldn't delete banks — see error details");

    // Refresh: the stale row drops, the selection recalculates (2 → 1), and
    // focus lands back on the bulk bar's Delete.
    searchRun.mockResolvedValue({ banks: [ACME], nextCursor: undefined });
    fireEvent.click(screen.getByTestId("banks-list__delete-error-refresh"));

    await waitFor(() => {
      expect(screen.queryByTestId("banks-list__delete-error")).toBeNull();
    });
    await waitFor(() => {
      expect(screen.queryByTestId(`banks-table__row-${BETA.id}`)).toBeNull();
    });
    expect(await screen.findByTestId("banks-list__bulk-count")).toHaveTextContent("1 selected");
    await waitFor(() => {
      expect(screen.getByTestId("banks-list__bulk-delete")).toHaveFocus();
    });
  });

  it("fails open to the attempt when a probe errors with something other than 404", async () => {
    findRun.mockImplementation((id: string) => {
      if (id === BETA.id) return Promise.reject(new Error("probe blip"));
      return Promise.resolve(ACME);
    });
    render(<BanksListPage />);
    await screen.findByTestId(`banks-table__row-${ACME.id}`);

    fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));
    fireEvent.click(screen.getByLabelText(`Select row ${BETA.id}`));
    fireEvent.click(screen.getByTestId("banks-list__bulk-delete"));
    fireEvent.click(await screen.findByTestId("banks-list__bulk-delete-confirm"));

    await waitFor(() => {
      expect(deleteRun).toHaveBeenCalledWith(ACME.id);
      expect(deleteRun).toHaveBeenCalledWith(BETA.id);
    });
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
