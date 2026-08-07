import { fireEvent, screen } from "@testing-library/react";
import { openRowMenuItem } from "../_interactions";

/**
 * Opens a bank-ish row's `⋯` overflow menu and returns its Delete item, through the
 * retrying open in {@link openRowMenuItem} — a single-shot open is a jsdom race that
 * survives locally and goes red under CI parallelism.
 *
 * `surface` is the testid surface owning the row — `banks-table`, `banks-cards`,
 * `bank-accounts-table` — per the grammar in `docs/adr/test-id-naming-contract.md`.
 */
export async function openRowDeleteItem(surface: string, id: string): Promise<HTMLElement> {
  return openRowMenuItem(surface, "delete", id);
}

/** Opens the banks-table row's delete menu item and confirms the dialog. */
export async function confirmDeleteOf(id: string): Promise<void> {
  fireEvent.click(await openRowDeleteItem("banks-table", id));
  fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));
}
