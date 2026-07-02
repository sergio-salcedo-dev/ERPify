import { createColumnPreference } from "@/context/shared/resource/domain/ColumnPreference";

/**
 * Column catalog for the global accounts table. `bank` (the owning bank name),
 * `holderName`, and `iban` are the pinned core; the rest are toggleable. Keys
 * that double as sort fields (`holderName`) match the API's sort vocabulary so
 * the table header sort maps 1:1; the display-only keys (`bank`/`iban`/…) never
 * reach the sort dropdown.
 */
export const BANK_ACCOUNT_COLUMN_KEYS = [
  "bank",
  "holderName",
  "iban",
  "alias",
  "bic",
  "currency",
  "status",
] as const;
export type BankAccountColumnKey = (typeof BANK_ACCOUNT_COLUMN_KEYS)[number];

/** `bank`, `holderName` and `iban` are pinned (always visible); the rest toggle. */
export const PINNED_COLUMNS: readonly BankAccountColumnKey[] = ["bank", "holderName", "iban"];
export const TOGGLEABLE_COLUMNS: readonly BankAccountColumnKey[] = [
  "alias",
  "bic",
  "currency",
  "status",
];
export const DEFAULT_VISIBLE_COLUMNS: readonly BankAccountColumnKey[] = [
  "bank",
  "holderName",
  "iban",
  "alias",
  "status",
];
export const BANK_ACCOUNTS_COLUMNS_STORAGE_KEY = "erpify:bank-accounts-columns";

const preference = createColumnPreference<BankAccountColumnKey>({
  allKeys: BANK_ACCOUNT_COLUMN_KEYS,
  pinned: PINNED_COLUMNS,
});

export const serializeColumns = preference.serialize;
export const parseColumns = preference.parse;
export const isStoredColumnsValue = preference.isStoredValue;
