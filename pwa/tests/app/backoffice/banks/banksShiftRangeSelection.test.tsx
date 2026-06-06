import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { BankRealtimeHandlers } from "@/context/backoffice/bank/infrastructure/bankRealtime";
import { ACME, BETA } from "./_fixtures";

/**
 * Shift+↑/↓ range selection (Explorer semantics): the first press anchors on
 * the focused row and snapshots the selection as baseline; each press
 * recomputes `baseline ∪ range[anchor..focus]`, so contracting deselects only
 * what the range itself added. Any non-shift movement resets the anchor.
 */

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const searchRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", async () =>
  (await import("./_mocks")).containerMock({ BackOfficeSearchBanks: { run: searchRun } }),
);

vi.mock("@/context/shared/infrastructure/Notification/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

// Captured so tests can shrink the list from under an armed range anchor.
let realtimeHandlers: BankRealtimeHandlers | undefined;
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", async () =>
  (await import("./_mocks")).bankRealtimeMock((handlers) => {
    realtimeHandlers = handlers;
  }),
);

// Third row so ranges can grow and contract around a midpoint.
const GAMMA = Bank.fromPrimitives({
  ...ACME,
  id: "33333333-3333-4333-8333-333333333333",
  name: "Gamma Trust",
  shortName: "GAMMA",
});

const ROWS = [ACME, BETA, GAMMA];

async function renderWithRows(): Promise<void> {
  render(<BanksListPage />);
  await screen.findByTestId(`banks-table__row-${ACME.id}`);
}

function tableRow(bank: Bank): HTMLElement {
  return screen.getByTestId(`banks-table__row-${bank.id}`);
}

function rowCheckbox(bank: Bank): HTMLElement {
  return screen.getByLabelText(`Select row ${bank.id}`);
}

async function expectSelectionCount(count: number): Promise<void> {
  expect(await screen.findByTestId("banks-list__bulk-count")).toHaveTextContent(
    `${count} selected`,
  );
}

describe("BanksListPage — Shift+Arrow range selection", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    realtimeHandlers = undefined;
    searchRun.mockResolvedValue({ banks: ROWS, nextCursor: undefined });
  });

  it("anchors on the focused row and extends the range with each Shift+ArrowDown", async () => {
    await renderWithRows();

    const first = tableRow(ACME);
    first.focus();
    fireEvent.keyDown(first, { key: "ArrowDown", shiftKey: true });

    await expectSelectionCount(2);
    expect(rowCheckbox(ACME)).toBeChecked();
    expect(rowCheckbox(BETA)).toBeChecked();

    // Focus moved to BETA's row; extending again reaches GAMMA.
    fireEvent.keyDown(tableRow(BETA), { key: "ArrowDown", shiftKey: true });

    await expectSelectionCount(3);
    expect(rowCheckbox(GAMMA)).toBeChecked();
  });

  it("contracting deselects only what the range added — the baseline survives", async () => {
    await renderWithRows();

    // GAMMA pre-selected: it is the baseline the range must never destroy.
    fireEvent.click(rowCheckbox(GAMMA));
    await expectSelectionCount(1);

    const first = tableRow(ACME);
    first.focus();
    fireEvent.keyDown(first, { key: "ArrowDown", shiftKey: true });
    await expectSelectionCount(3);

    // Contract back to the anchor: BETA (added by the range) drops, GAMMA stays.
    fireEvent.keyDown(tableRow(BETA), { key: "ArrowUp", shiftKey: true });

    await expectSelectionCount(2);
    expect(rowCheckbox(ACME)).toBeChecked();
    expect(rowCheckbox(BETA)).not.toBeChecked();
    expect(rowCheckbox(GAMMA)).toBeChecked();
  });

  it("resets the anchor on movement without Shift", async () => {
    await renderWithRows();

    const first = tableRow(ACME);
    first.focus();
    fireEvent.keyDown(first, { key: "ArrowDown", shiftKey: true });
    await expectSelectionCount(2);

    // Plain ArrowDown to GAMMA ends the range; the next Shift+ArrowUp anchors
    // there — with the old anchor it would have recomputed [ACME..BETA] and
    // left the count at 2.
    fireEvent.keyDown(tableRow(BETA), { key: "ArrowDown" });
    fireEvent.keyDown(tableRow(GAMMA), { key: "ArrowUp", shiftKey: true });

    await expectSelectionCount(3);
    expect(rowCheckbox(GAMMA)).toBeChecked();
  });

  it("ends the range when the selection changes externally (bulk-bar Clear)", async () => {
    await renderWithRows();

    const first = tableRow(ACME);
    first.focus();
    fireEvent.keyDown(first, { key: "ArrowDown", shiftKey: true });
    await expectSelectionCount(2);

    fireEvent.click(screen.getByTestId("banks-list__bulk-clear"));
    await waitFor(() => {
      expect(screen.queryByTestId("banks-list__bulk-bar")).toBeNull();
    });

    // A fresh Shift+ArrowDown must anchor on the now-focused row — not revive
    // the cleared baseline through a stale anchor.
    act(() => tableRow(BETA).focus());
    fireEvent.keyDown(tableRow(BETA), { key: "ArrowDown", shiftKey: true });

    await expectSelectionCount(2);
    expect(rowCheckbox(ACME)).not.toBeChecked();
    expect(rowCheckbox(BETA)).toBeChecked();
    expect(rowCheckbox(GAMMA)).toBeChecked();
  });

  it("re-arms on fresh indices after a realtime delete shrinks the list under the anchor", async () => {
    await renderWithRows();

    // Anchor at the LAST row (index 2), extending upwards.
    act(() => tableRow(GAMMA).focus());
    fireEvent.keyDown(tableRow(GAMMA), { key: "ArrowUp", shiftKey: true });
    await expectSelectionCount(2);

    // An unselected row is deleted remotely: the slice shrinks to 2 rows while
    // the cached anchor still points at index 2 and the selection set keeps
    // its identity (nothing to prune).
    act(() => realtimeHandlers?.onDeleted?.(ACME.id));
    await waitFor(() => {
      expect(screen.queryByTestId(`banks-table__row-${ACME.id}`)).toBeNull();
    });

    // The next Shift+ArrowDown must re-arm on current indices instead of
    // walking past the end of the shrunk list.
    act(() => tableRow(BETA).focus());
    fireEvent.keyDown(tableRow(BETA), { key: "ArrowDown", shiftKey: true });

    await expectSelectionCount(2);
    expect(rowCheckbox(BETA)).toBeChecked();
    expect(rowCheckbox(GAMMA)).toBeChecked();
  });

  it("mirrors the range contract on the stacked-list surface", async () => {
    await renderWithRows();

    const first = screen.getByTestId(`banks-stacked__row-${ACME.id}`);
    first.focus();
    fireEvent.keyDown(first, { key: "ArrowDown", shiftKey: true });

    await expectSelectionCount(2);
    expect(screen.getByTestId(`banks-stacked__select-${ACME.id}`)).toBeChecked();
    expect(screen.getByTestId(`banks-stacked__select-${BETA.id}`)).toBeChecked();
  });
});
