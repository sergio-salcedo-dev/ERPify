import { render, screen } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { ACME } from "./_fixtures";

/**
 * Renders `BanksListPage` under the spec's registered module mocks and
 * resolves once ACME's table row is on screen — the shared "list is ready"
 * entry point for list-page specs.
 */
export async function renderWithRows(): Promise<void> {
  render(<BanksListPage />);
  await screen.findByTestId(`banks-table__row-${ACME.id}`);
}
