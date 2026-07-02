import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BankAccountsStackedList } from "@/app/backoffice/bank-accounts/_components/BankAccountsStackedList";
import { BankAccountsBulkBar } from "@/app/backoffice/bank-accounts/_components/BankAccountsBulkBar";
import { BankAccountsEmptyFiltered } from "@/app/backoffice/bank-accounts/_components/BankAccountsEmptyFiltered";
import { ACME_CHECKING, BETA_RESERVE } from "./_fixtures";

// BankAccountRowActions (used by the stacked list) imports the real DI container at module
// scope; it is only touched on delete confirm, never at render. Neutralise it here.
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({}),
);

describe("BankAccountsStackedList", () => {
  it("renders one card-row per account with the holder link and bank name", () => {
    render(
      <BankAccountsStackedList
        accounts={[ACME_CHECKING, BETA_RESERVE]}
        onAccountDeleteFailed={vi.fn()}
      />,
    );

    expect(
      screen.getByTestId(`bank-accounts-stacked__row-${ACME_CHECKING.id}`),
    ).toBeInTheDocument();
    expect(screen.getByText("Beta Bank")).toBeInTheDocument();
  });

  it("wires the per-row selection checkbox to onToggleSelect", () => {
    const onToggleSelect = vi.fn();
    render(
      <BankAccountsStackedList
        accounts={[ACME_CHECKING]}
        onAccountDeleteFailed={vi.fn()}
        onToggleSelect={onToggleSelect}
        selectedIds={new Set([ACME_CHECKING.id])}
      />,
    );

    const checkbox = screen.getByTestId(`bank-accounts-stacked__select-${ACME_CHECKING.id}`);
    expect(checkbox).toBeChecked();
    fireEvent.click(checkbox);
    expect(onToggleSelect).toHaveBeenCalledWith(ACME_CHECKING.id);
  });
});

describe("BankAccountsBulkBar", () => {
  it("renders the bulk delete trigger even when no holder names are supplied", () => {
    render(<BankAccountsBulkBar count={3} onClear={vi.fn()} onConfirmDelete={vi.fn()} />);

    expect(screen.getByTestId("bank-accounts-list__bulk-delete")).toBeInTheDocument();
  });
});

describe("BankAccountsEmptyFiltered", () => {
  it("invokes onReset when Clear all is pressed", () => {
    const onReset = vi.fn();
    render(<BankAccountsEmptyFiltered onReset={onReset} />);

    fireEvent.click(screen.getByTestId("bank-accounts-list__reset-filters"));

    expect(onReset).toHaveBeenCalledTimes(1);
  });
});
