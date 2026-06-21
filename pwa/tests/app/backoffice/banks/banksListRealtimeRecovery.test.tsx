import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { ACME, BETA, searchPage } from "./_fixtures";

/**
 * When the initial list load fails the page shows the error boundary. A bank
 * created elsewhere then arrives over Mercure. Because Mercure has no replay,
 * that single delta is NOT the full list — the page must reconcile with a
 * silent full reload (recovering the complete dataset) rather than promoting
 * the partial delta straight to the ready list.
 */

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const searchRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", async () =>
  (await import("./_mocks")).containerMock({ BackOfficeSearchBanks: { run: searchRun } }),
);

vi.mock("@/context/shared/Notification/infrastructure/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

// Capture the realtime handlers so the test can drive Mercure events directly,
// without standing up an EventSource. The mock keeps the real `bankTopics` (so
// the topic IRI can't drift from production) and replaces only the hook.
const realtime = vi.hoisted(() => ({
  onCreated: undefined as ((bank: Bank) => void) | undefined,
  onUpdated: undefined as ((bank: Bank) => void) | undefined,
  onDeleted: undefined as ((id: string) => void) | undefined,
}));
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", async () =>
  (await import("./_mocks")).bankRealtimeMock((handlers) => {
    realtime.onCreated = handlers.onCreated;
    realtime.onUpdated = handlers.onUpdated;
    realtime.onDeleted = handlers.onDeleted;
  }),
);

describe("BanksListPage — realtime recovery from an errored load", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    realtime.onCreated = undefined;
    realtime.onUpdated = undefined;
    realtime.onDeleted = undefined;
  });

  it("silently reloads the full list when a bank arrives over Mercure while the load is errored", async () => {
    searchRun
      .mockRejectedValueOnce(new Error("network down"))
      .mockResolvedValueOnce(searchPage([ACME, BETA]));

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

  it("silently reloads the full list when an updated-bank delta arrives while errored", async () => {
    searchRun
      .mockRejectedValueOnce(new Error("network down"))
      .mockResolvedValueOnce(searchPage([ACME, BETA]));

    render(<BanksListPage />);

    await screen.findByTestId("banks-list__retry");
    expect(searchRun).toHaveBeenCalledTimes(1);

    act(() => {
      realtime.onUpdated?.(BETA);
    });

    await waitFor(() => {
      expect(screen.getByRole("cell", { name: "Acme Savings" })).toBeInTheDocument();
    });
    expect(searchRun).toHaveBeenCalledTimes(2);
    expect(screen.queryByTestId("banks-list__retry")).toBeNull();
  });

  it("silently reloads the full list when a deleted-bank delta arrives while errored", async () => {
    searchRun
      .mockRejectedValueOnce(new Error("network down"))
      .mockResolvedValueOnce(searchPage([ACME]));

    render(<BanksListPage />);

    await screen.findByTestId("banks-list__retry");
    expect(searchRun).toHaveBeenCalledTimes(1);

    act(() => {
      realtime.onDeleted?.(BETA.id);
    });

    await waitFor(() => {
      expect(screen.getByRole("cell", { name: "Acme Savings" })).toBeInTheDocument();
    });
    expect(searchRun).toHaveBeenCalledTimes(2);
    expect(screen.queryByTestId("banks-list__retry")).toBeNull();
  });

  it("coalesces a burst of deltas while errored into a single silent reload", async () => {
    searchRun
      .mockRejectedValueOnce(new Error("network down"))
      .mockResolvedValueOnce(searchPage([ACME, BETA]));

    render(<BanksListPage />);

    await screen.findByTestId("banks-list__retry");
    expect(searchRun).toHaveBeenCalledTimes(1);

    // Three deltas land before the first reconcile reload resolves; the in-flight
    // guard must collapse them into ONE silent reload, not three overlapping ones.
    act(() => {
      realtime.onCreated?.(BETA);
      realtime.onUpdated?.(BETA);
      realtime.onDeleted?.(ACME.id);
    });

    await waitFor(() => {
      expect(screen.getByRole("cell", { name: "Acme Savings" })).toBeInTheDocument();
    });
    expect(searchRun).toHaveBeenCalledTimes(2);
    expect(screen.queryByTestId("banks-list__retry")).toBeNull();
  });
});
