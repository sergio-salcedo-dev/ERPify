import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BankAccountsCards } from "@/app/backoffice/bank-accounts/_components/BankAccountsCards";
import { ACME_CHECKING, BETA_RESERVE } from "./_fixtures";

// BankAccountRowActions imports the real DI container at module scope (only used on
// delete confirm, never at render). Neutralise it so the card grid renders in isolation.
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({}),
);

describe("BankAccountsCards", () => {
  it("renders one card per account with holder, bank, alias and currency", () => {
    render(
      <BankAccountsCards
        accounts={[ACME_CHECKING, BETA_RESERVE]}
        onAccountDeleteFailed={vi.fn()}
      />,
    );

    expect(screen.getByTestId(`bank-accounts-cards__item-${ACME_CHECKING.id}`)).toBeInTheDocument();
    expect(screen.getByTestId(`bank-accounts-cards__holder-${ACME_CHECKING.id}`)).toHaveTextContent(
      "Alice Holder",
    );
    expect(screen.getByText("Acme Savings")).toBeInTheDocument();
    // Alias present on ACME, absent (em dash) on BETA.
    expect(screen.getByText("Payroll")).toBeInTheDocument();
    expect(screen.getByText("—")).toBeInTheDocument();
    expect(screen.getAllByText("EUR")).toHaveLength(2);
  });

  it("wires the selection checkbox to onToggleSelect when selection is enabled", () => {
    const onToggleSelect = vi.fn();
    render(
      <BankAccountsCards
        accounts={[ACME_CHECKING]}
        onAccountDeleteFailed={vi.fn()}
        onToggleSelect={onToggleSelect}
        selectedIds={new Set()}
      />,
    );

    fireEvent.click(screen.getByTestId(`bank-accounts-cards__select-${ACME_CHECKING.id}`));

    expect(onToggleSelect).toHaveBeenCalledWith(ACME_CHECKING.id);
  });

  it("omits the selection checkbox when selection is not enabled", () => {
    render(<BankAccountsCards accounts={[ACME_CHECKING]} onAccountDeleteFailed={vi.fn()} />);

    expect(screen.queryByTestId(`bank-accounts-cards__select-${ACME_CHECKING.id}`)).toBeNull();
  });
});
