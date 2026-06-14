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

const COLUMNS_SEPARATOR = ",";

/** Serialize the visible set to a CSV string for `useStoredPreference` (string-only). */
export function serializeColumns(keys: readonly BankColumnKey[]): string {
  return keys.join(COLUMNS_SEPARATOR);
}

/** Parse the CSV back to keys; the pinned columns are always present. */
export function parseColumns(raw: string): BankColumnKey[] {
  const parts = raw
    .split(COLUMNS_SEPARATOR)
    .filter((p): p is BankColumnKey => (BANK_COLUMN_KEYS as readonly string[]).includes(p));
  const missingPinned = PINNED_COLUMNS.filter((k) => !parts.includes(k));
  return [...missingPinned, ...parts];
}

/** Validate a stored CSV value: every token must be a known column key. */
export function isStoredColumnsValue(v: string): v is string {
  return (
    v.length > 0 &&
    v.split(COLUMNS_SEPARATOR).every((p) => (BANK_COLUMN_KEYS as readonly string[]).includes(p))
  );
}
