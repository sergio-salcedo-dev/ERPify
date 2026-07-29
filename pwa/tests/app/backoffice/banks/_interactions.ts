import { fireEvent, screen } from "@testing-library/react";

/** Opens attempted on a row overflow menu before the failure is allowed to surface. */
const OPEN_ATTEMPTS = 3;

/**
 * Opens a row's `⋯` overflow menu and returns its Delete item.
 *
 * The menu is a Base UI popup rendered through a portal, and under jsdom churn a
 * just-opened menu can close again before its content mounts — so the OPEN is
 * retried. Only the open: the final attempt awaits the item single-shot, so a
 * Delete that genuinely never renders still fails instead of being masked.
 *
 * `surface` is the testid surface owning the row — `banks-table`, `banks-cards`,
 * `bank-accounts-table` — per the grammar in `docs/adr/test-id-naming-contract.md`.
 * It is a parameter rather than one copy of this helper per surface because a copy
 * written without the retry is what makes a row-menu spec red once in a thousand runs.
 */
export async function openRowDeleteItem(surface: string, id: string): Promise<HTMLElement> {
  for (let attempt = 1; attempt < OPEN_ATTEMPTS; attempt += 1) {
    fireEvent.click(screen.getByTestId(`${surface}__actions-${id}`));
    try {
      return await screen.findByTestId(`${surface}__delete-${id}`);
    } catch {
      // The item never rendered — the open lost the race; re-open the menu.
    }
  }

  fireEvent.click(screen.getByTestId(`${surface}__actions-${id}`));

  return screen.findByTestId(`${surface}__delete-${id}`);
}

/** Opens the banks-table row's delete menu item and confirms the dialog. */
export async function confirmDeleteOf(id: string): Promise<void> {
  fireEvent.click(await openRowDeleteItem("banks-table", id));
  fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));
}
