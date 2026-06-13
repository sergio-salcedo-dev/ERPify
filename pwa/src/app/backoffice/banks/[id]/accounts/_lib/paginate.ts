// Page-size options for the bank-accounts list, all ≤ WIRE_MAX_LIMIT (100,
// D-Cap): the API caps `limit` at 100 (422 otherwise). `paginate.test.ts` pins
// the ≤-cap invariant against the single source of truth (`WIRE_MAX_LIMIT`), so
// a future option can never exceed it.
export const BANK_ACCOUNTS_PAGE_SIZE_OPTIONS = [25, 50, 100] as const;
export type BankAccountsPageSize = (typeof BANK_ACCOUNTS_PAGE_SIZE_OPTIONS)[number];
export const BANK_ACCOUNTS_PAGE_SIZE_DEFAULT: BankAccountsPageSize = 25;
