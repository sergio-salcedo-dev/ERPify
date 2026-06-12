import { fireEvent, screen } from "@testing-library/react";

/**
 * Shared row-delete interactions for the banks list specs. The row menu is a
 * Radix dropdown rendered through a portal: under jsdom churn a just-opened menu
 * can close again before its items render, so the OPEN is retried (never the
 * assertions — those stay single-shot).
 */
export async function openDeleteItem(id: string): Promise<HTMLElement> {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    fireEvent.click(screen.getByTestId(`banks-table__actions-${id}`));
    try {
      return await screen.findByTestId(`banks-table__delete-${id}`);
    } catch {
      // The item never rendered — the open lost the race; re-open the menu.
    }
  }
  fireEvent.click(screen.getByTestId(`banks-table__actions-${id}`));
  return screen.findByTestId(`banks-table__delete-${id}`);
}

/** Opens the row's delete menu item and confirms the dialog. */
export async function confirmDeleteOf(id: string): Promise<void> {
  fireEvent.click(await openDeleteItem(id));
  fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));
}
