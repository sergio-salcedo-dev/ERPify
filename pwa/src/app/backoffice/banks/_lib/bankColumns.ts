import { createColumnPreference } from "@/lib/columnPreference";

export const BANK_COLUMN_KEYS = [
  "shortName",
  "name",
  "status",
  "accounts",
  "updatedAt",
  "createdAt",
] as const;
export type BankColumnKey = (typeof BANK_COLUMN_KEYS)[number];
/** `shortName` and `name` are pinned (always visible); the rest are toggleable. */
export const PINNED_COLUMNS: readonly BankColumnKey[] = ["shortName", "name"];
export const TOGGLEABLE_COLUMNS: readonly BankColumnKey[] = [
  "status",
  "accounts",
  "updatedAt",
  "createdAt",
];
export const DEFAULT_VISIBLE_COLUMNS: readonly BankColumnKey[] = [
  "shortName",
  "name",
  "status",
  "accounts",
  "updatedAt",
  "createdAt",
];
export const BANKS_COLUMNS_STORAGE_KEY = "erpify:banks-columns";

const preference = createColumnPreference<BankColumnKey>({
  allKeys: BANK_COLUMN_KEYS,
  pinned: PINNED_COLUMNS,
});

export const serializeColumns = preference.serialize;
export const parseColumns = preference.parse;
export const isStoredColumnsValue = preference.isStoredValue;
