import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { BankAccountsTable } from "@/app/backoffice/bank-accounts/_components/BankAccountsTable";
import { BANK_ACCOUNT_COLUMN_KEYS } from "@/app/backoffice/bank-accounts/_lib/bankAccountColumns";
import { maskIban } from "@/app/backoffice/banks/[id]/accounts/_components/IbanCell";
import { ACME_CHECKING, BETA_RESERVE } from "./_fixtures";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

describe("BankAccountsTable — cross-bank identity", () => {
  it("shows each row's owning bank name and short code", () => {
    render(
      <BankAccountsTable
        accounts={[ACME_CHECKING, BETA_RESERVE]}
        visible={[...BANK_ACCOUNT_COLUMN_KEYS]}
        onAccountDeleteFailed={() => {}}
      />,
    );

    expect(screen.getByTestId(`bank-accounts-list__bank-${ACME_CHECKING.id}`)).toHaveTextContent(
      "Acme Savings",
    );
    expect(screen.getByTestId(`bank-accounts-list__bank-${BETA_RESERVE.id}`)).toHaveTextContent(
      "Beta Bank",
    );
    expect(screen.getByRole("cell", { name: "Alice Holder" })).toBeInTheDocument();
  });

  it("masks the IBAN by default and never renders the integral value at rest", () => {
    render(
      <BankAccountsTable
        accounts={[ACME_CHECKING]}
        visible={[...BANK_ACCOUNT_COLUMN_KEYS]}
        onAccountDeleteFailed={() => {}}
      />,
    );

    const cell = screen.getByTestId(`bank-accounts-list__iban-${ACME_CHECKING.id}`);
    expect(cell).toHaveTextContent(maskIban(ACME_CHECKING.iban));
    expect(cell.textContent).not.toContain(ACME_CHECKING.iban);
  });

  it("reveals the integral IBAN only after the reveal toggle is pressed", () => {
    render(
      <BankAccountsTable
        accounts={[ACME_CHECKING]}
        visible={[...BANK_ACCOUNT_COLUMN_KEYS]}
        onAccountDeleteFailed={() => {}}
      />,
    );

    const cell = screen.getByTestId(`bank-accounts-list__iban-${ACME_CHECKING.id}`);
    fireEvent.click(within(cell).getByRole("button", { name: "Show IBAN" }));

    expect(cell).toHaveTextContent(ACME_CHECKING.iban);
  });
});
