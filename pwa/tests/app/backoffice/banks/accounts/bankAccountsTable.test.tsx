import { describe, expect, it } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { BankAccountsTable } from "@/app/backoffice/banks/[id]/accounts/_components/BankAccountsTable";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";

const WITH_ALIAS = BankAccount.fromPrimitives({
  id: "11111111-1111-7111-8111-111111111111",
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: "CAIXESBBXXX",
  alias: "Payroll",
  currency: "EUR",
  status: "ACTIVE",
});

const NO_ALIAS = BankAccount.fromPrimitives({
  id: "22222222-2222-7222-8222-222222222222",
  holderName: "Globex SA",
  iban: "DE89370400440532013000",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "CLOSED",
});

describe("BankAccountsTable", () => {
  it("renders the five columns in order", () => {
    render(<BankAccountsTable accounts={[WITH_ALIAS]} />);
    const headers = screen.getAllByRole("columnheader").map((h) => h.textContent);
    expect(headers).toEqual(["Holder", "IBAN", "Alias", "Currency", "Status"]);
  });

  it("keys each row by the backend UUID via rowTestId", () => {
    render(<BankAccountsTable accounts={[WITH_ALIAS, NO_ALIAS]} />);
    expect(screen.getByTestId(`bank-accounts-table__row-${WITH_ALIAS.id}`)).toBeInTheDocument();
    expect(screen.getByTestId(`bank-accounts-table__row-${NO_ALIAS.id}`)).toBeInTheDocument();
  });

  it("masks the IBAN by default and shows currency + status", () => {
    render(<BankAccountsTable accounts={[WITH_ALIAS]} />);
    expect(screen.getByText("ES•• ···· 1332")).toBeInTheDocument();
    expect(screen.queryByText(WITH_ALIAS.iban)).toBeNull();
    expect(screen.getByText("EUR")).toBeInTheDocument();
    expect(screen.getByText("Active")).toBeInTheDocument();
  });

  it("renders a placeholder for a null alias", () => {
    render(<BankAccountsTable accounts={[NO_ALIAS]} />);
    const row = screen.getByTestId(`bank-accounts-table__row-${NO_ALIAS.id}`);
    expect(within(row).getByText("—")).toBeInTheDocument();
    expect(within(row).getByText("Closed")).toBeInTheDocument();
  });
});
