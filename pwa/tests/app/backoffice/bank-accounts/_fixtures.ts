import type { ResourceSearchPage } from "@/context/shared/resource/domain/CrudRepository";
import type { BankAccountCollectionRow } from "@/context/backoffice/bankaccount/domain/BankAccountCollectionRow";

/**
 * Builds a `ResourceSearchPage` in the shape the generic
 * `BackOfficeBankAccountCrudRepository.search` resolves to (items in `items`,
 * cursor-only envelope). Defaults to a complete single page; pass `overrides`
 * to vary any field — e.g. `{ hasNext: true, links: { next: "/api/…", prev: null } }`.
 */
export function resourcePage(
  rows: BankAccountCollectionRow[],
  overrides: Partial<ResourceSearchPage<BankAccountCollectionRow>> = {},
): ResourceSearchPage<BankAccountCollectionRow> {
  return {
    items: rows,
    hasNext: false,
    hasPrev: false,
    count: null,
    links: { next: null, prev: null },
    ...overrides,
  };
}

/**
 * Canonical cross-bank account rows for the list specs. Shared so every spec
 * exercises the same ids/names. Deeply immutable by convention.
 */
export const ACME_CHECKING: BankAccountCollectionRow = {
  id: "aaaaaaaa-aaaa-4aaa-8aaa-000000000001",
  bankId: "11111111-1111-4111-8111-111111111111",
  bankName: "Acme Savings",
  bankShortName: "ACME",
  holderName: "Alice Holder",
  iban: "ES9121000418450200051332",
  bic: "CAIXESBBXXX",
  alias: "Payroll",
  currency: "EUR",
  status: "ACTIVE",
};

export const BETA_RESERVE: BankAccountCollectionRow = {
  id: "bbbbbbbb-bbbb-4bbb-8bbb-000000000002",
  bankId: "22222222-2222-4222-8222-222222222222",
  bankName: "Beta Bank",
  bankShortName: "BETA",
  holderName: "Bob Holder",
  iban: "DE89370400440532013000",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "CLOSED",
};
