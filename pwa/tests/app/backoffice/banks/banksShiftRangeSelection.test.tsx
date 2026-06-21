import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, screen, waitFor } from "@testing-library/react";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { ACME, BETA, searchPage } from "./_fixtures";
import { renderWithRows } from "./_render";

/**
 * Shift+↑/↓ range selection (Explorer semantics): the first press anchors on
 * the focused row and snapshots the selection as baseline; each press
 * recomputes `baseline ∪ range[anchor..focus]`, so contracting deselects only
 * what the range itself added. Any non-shift movement resets the anchor.
 */

// Third row so ranges can grow and contract around a midpoint.
const GAMMA = Bank.fromPrimitives({
  ...ACME,
  id: "33333333-3333-4333-8333-333333333333",
  name: "Gamma Trust",
  shortName: "GAMMA",
});

const ROWS = [ACME, BETA, GAMMA];

// `realtime.handlers` lets tests shrink the list from under an armed range anchor.
const mocks = await vi.hoisted(async () => (await import("./_mocks")).banksListPageMocks());

vi.mock("next/navigation", mocks.navigation);
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", mocks.container);
vi.mock("@/context/shared/notification/infrastructure/Toast", mocks.toast);
vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", mocks.bankRealtime);

const { searchRun, realtime } = mocks;

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
    realtime.handlers = undefined;
    searchRun.mockResolvedValue(searchPage(ROWS));
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

    // An unselected row is deleted remotely: the silent refetch returns the
    // shrunk list (2 rows) while the cached anchor still points at index 2 and
    // the selection set keeps its identity (nothing to prune).
    searchRun.mockResolvedValue(searchPage([BETA, GAMMA]));
    act(() => realtime.handlers?.onDeleted?.(ACME.id));
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
