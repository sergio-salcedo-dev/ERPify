import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import { ACME, BETA, searchPage } from "./_fixtures";

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const searchRun = vi.hoisted(() => vi.fn());
const deleteRun = vi.hoisted(() => vi.fn());
const findRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeSearchBanks: { run: searchRun },
    BackOfficeDeleteBank: { run: deleteRun },
    BackOfficeFindBank: { run: findRun },
  }),
);

vi.mock("@/context/shared/infrastructure/Notification/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

const STALE_PROBLEM: ProblemDetails = {
  type: "bank-not-found",
  title: "Bank not found",
  status: 404,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000404",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000404",
};

const IN_USE_PROBLEM: ProblemDetails = {
  type: "bank-in-use",
  title: "Bank still has associated accounts",
  status: 409,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000409",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000409",
};

/** Deletes succeed except BETA's, which rejects with the given error. */
function failBetaDelete(error: unknown = new Error("boom")) {
  deleteRun.mockImplementation((id: string) =>
    id === BETA.id ? Promise.reject(error) : Promise.resolve(undefined),
  );
}

/**
 * Pre-check (1st findBank call per id) resolves; the re-probe (2nd call) for
 * BETA rejects with the given error — e.g. another client deleted it during
 * the probe/delete window.
 */
function rejectBetaReprobe(error: unknown) {
  const findCalls = new Map<string, number>();
  findRun.mockImplementation((id: string) => {
    const count = (findCalls.get(id) ?? 0) + 1;
    findCalls.set(id, count);
    if (id === BETA.id && count >= 2) return Promise.reject(error);
    return Promise.resolve(id === ACME.id ? ACME : BETA);
  });
}

async function renderWithRows() {
  render(<BanksListPage />);
  await screen.findByTestId(`banks-table__row-${ACME.id}`);
}

function selectRows(...banks: Bank[]) {
  for (const bank of banks) {
    fireEvent.click(screen.getByLabelText(`Select row ${bank.id}`));
  }
}

async function confirmBulkDelete() {
  fireEvent.click(screen.getByTestId("banks-list__bulk-delete"));
  fireEvent.click(await screen.findByTestId("banks-list__bulk-delete-confirm"));
}

/** ACME's delete succeeded (row stays gone); BETA's failed — restored AND re-selected. */
async function expectBetaRestoredAndReselected() {
  await waitFor(() => {
    expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
  });
  expect(await screen.findByTestId(`banks-table__row-${BETA.id}`)).toBeInTheDocument();
  // The selection of the failed row is restored too — retry without re-picking.
  expect(await screen.findByTestId("banks-list__bulk-count")).toHaveTextContent("1 selected");
}

describe("BanksListPage — bulk actions", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    searchRun.mockResolvedValue(searchPage([ACME, BETA]));
    deleteRun.mockResolvedValue(undefined);
    // The existence pre-check probes every selected id before the attempt.
    findRun.mockImplementation((id: string) => Promise.resolve(id === ACME.id ? ACME : BETA));
  });

  it("reveals a bulk bar with the selection count once rows are selected", async () => {
    await renderWithRows();

    expect(screen.queryByTestId("banks-list__bulk-bar")).toBeNull();

    selectRows(ACME);

    expect(await screen.findByTestId("banks-list__bulk-bar")).toBeInTheDocument();
    expect(screen.getByTestId("banks-list__bulk-count")).toHaveTextContent("1 selected");
  });

  it("bulk-deletes every selected bank and clears the selection", async () => {
    await renderWithRows();

    selectRows(ACME, BETA);
    expect(screen.getByTestId("banks-list__bulk-count")).toHaveTextContent("2 selected");

    await confirmBulkDelete();

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
    failBetaDelete();
    await renderWithRows();

    selectRows(ACME, BETA);
    await confirmBulkDelete();

    await expectBetaRestoredAndReselected();
    // The failure lives in the persistent surface; the toast only points at it.
    expect(await screen.findByTestId("banks-list__delete-error")).toBeInTheDocument();
    expect(toastNotifier.error).toHaveBeenCalledWith("Some banks could not be deleted", {
      description: "1 of 2 could not be deleted. See error details.",
    });
  });

  it("a bulk 409 bank-in-use orients to the per-row guard instead of deep-linking one bank", async () => {
    failBetaDelete(new HttpError(IN_USE_PROBLEM));
    await renderWithRows();

    selectRows(ACME, BETA);
    await confirmBulkDelete();

    await expectBetaRestoredAndReselected();
    const surface = await screen.findByTestId("banks-list__delete-error");
    expect(surface).toBeInTheDocument();
    // A bulk failure can involve several in-use banks, so the surface orients
    // the user to the restored rows (each carries its own guard) rather than
    // deep-linking only the first failed bank.
    expect(screen.getByTestId("banks-list__delete-error-in-use-hint")).toBeInTheDocument();
    expect(screen.queryByTestId("banks-list__delete-error-view-accounts")).toBeNull();
  });

  it("404 rejections do not resurrect the row (the bank is already gone)", async () => {
    failBetaDelete(new HttpError(STALE_PROBLEM));
    await renderWithRows();

    selectRows(ACME, BETA);
    await confirmBulkDelete();

    await screen.findByTestId("banks-list__delete-error");
    expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    expect(screen.queryByTestId(`banks-table__row-${BETA.id}`)).toBeNull();
    expect(screen.queryByTestId("banks-list__bulk-bar")).toBeNull();
  });

  it("does not resurrect a row whose re-probe 404s (deleted remotely mid-flight)", async () => {
    failBetaDelete();
    rejectBetaReprobe(new HttpError(STALE_PROBLEM));
    await renderWithRows();

    selectRows(ACME, BETA);
    await confirmBulkDelete();

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
    failBetaDelete();
    rejectBetaReprobe(new Error("probe blip"));
    await renderWithRows();

    selectRows(ACME, BETA);
    await confirmBulkDelete();

    // BETA is restored from the snapshot (fail-open).
    await expectBetaRestoredAndReselected();
    // Only BETA was re-probed: 2 pre-checks + 1 re-probe.
    expect(findRun).toHaveBeenCalledTimes(3);
  });

  it("aborts the whole bulk when the pre-check finds a stale id: nothing is deleted, Refresh recalculates", async () => {
    findRun.mockImplementation((id: string) =>
      id === BETA.id ? Promise.reject(new HttpError(STALE_PROBLEM)) : Promise.resolve(ACME),
    );
    await renderWithRows();

    selectRows(ACME, BETA);
    await confirmBulkDelete();

    const surface = await screen.findByTestId("banks-list__delete-error");
    expect(surface).toHaveTextContent(STALE_PROBLEM.title);
    expect(deleteRun).not.toHaveBeenCalled();
    // Both rows are still there — nothing mutated.
    expect(screen.getByTestId(`banks-table__row-${ACME.id}`)).toBeInTheDocument();
    expect(screen.getByTestId(`banks-table__row-${BETA.id}`)).toBeInTheDocument();
    expect(toastNotifier.error).toHaveBeenCalledWith("Couldn't delete banks — see error details");

    // Refresh: the stale row drops, the selection recalculates (2 → 1), and
    // focus lands back on the bulk bar's Delete.
    searchRun.mockResolvedValue(searchPage([ACME]));
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
    findRun.mockImplementation((id: string) =>
      id === BETA.id ? Promise.reject(new Error("probe blip")) : Promise.resolve(ACME),
    );
    await renderWithRows();

    selectRows(ACME, BETA);
    await confirmBulkDelete();

    await waitFor(() => {
      expect(deleteRun).toHaveBeenCalledWith(ACME.id);
      expect(deleteRun).toHaveBeenCalledWith(BETA.id);
    });
  });

  it("clears the selection via Clear without deleting anything", async () => {
    await renderWithRows();

    selectRows(ACME);
    fireEvent.click(await screen.findByTestId("banks-list__bulk-clear"));

    await waitFor(() => expect(screen.queryByTestId("banks-list__bulk-bar")).toBeNull());
    expect(deleteRun).not.toHaveBeenCalled();
  });

  it("floats the bulk bar at the bottom of the content column while a selection exists", async () => {
    await renderWithRows();
    selectRows(ACME);

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
