import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

/**
 * When the initial list load fails the page shows the error boundary. A bank
 * created elsewhere then arrives over Mercure. Because Mercure has no replay,
 * that single delta is NOT the full list — the page must reconcile with a
 * silent full reload (recovering the complete dataset) rather than promoting
 * the partial delta straight to the ready list.
 */

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

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

const searchRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", async () =>
  (await import("./_mocks")).containerMock({ BackOfficeSearchBanks: { run: searchRun } }),
);

vi.mock("@/context/shared/infrastructure/Notification/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

// Capture the realtime handlers so the test can drive a Mercure event directly,
// without standing up an EventSource.
const realtime = vi.hoisted(() => ({ onCreated: undefined as ((bank: Bank) => void) | undefined }));
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", () => ({
  bankTopics: { collection: "urn:erpify:backoffice:banks" },
  useBankRealtime: (_topics: readonly string[], handlers: { onCreated?: (bank: Bank) => void }) => {
    realtime.onCreated = handlers.onCreated;
  },
}));

describe("BanksListPage — realtime recovery from an errored load", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    realtime.onCreated = undefined;
  });

  it("silently reloads the full list when a bank arrives over Mercure while the load is errored", async () => {
    searchRun
      .mockRejectedValueOnce(new Error("network down"))
      .mockResolvedValueOnce({ banks: [ACME, BETA], nextCursor: undefined });

    render(<BanksListPage />);

    await screen.findByTestId("banks-list__retry");
    expect(searchRun).toHaveBeenCalledTimes(1);

    // A single created-bank delta arrives; the page must reconcile with a full
    // reload, not render only this one bank.
    act(() => {
      realtime.onCreated?.(BETA);
    });

    await waitFor(() => {
      expect(screen.getByRole("cell", { name: "Acme Savings" })).toBeInTheDocument();
    });
    // ACME was never in the delta — its presence proves the complete list was
    // reloaded rather than the partial Mercure delta being shown.
    expect(screen.getByRole("cell", { name: "Beta Bank" })).toBeInTheDocument();
    expect(searchRun).toHaveBeenCalledTimes(2);
    expect(screen.queryByTestId("banks-list__retry")).toBeNull();
  });
});
