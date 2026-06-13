export const USER_COLUMN_KEYS = ["email", "roles", "status", "createdAt", "updatedAt"] as const;
export type UserColumnKey = (typeof USER_COLUMN_KEYS)[number];
/** `email` is pinned (always visible); the rest are toggleable. */
export const PINNED_COLUMNS: readonly UserColumnKey[] = ["email"];
export const TOGGLEABLE_COLUMNS: readonly UserColumnKey[] = [
  "roles",
  "status",
  "createdAt",
  "updatedAt",
];
export const DEFAULT_VISIBLE_COLUMNS: readonly UserColumnKey[] = [
  "email",
  "roles",
  "status",
  "createdAt",
];
export const USERS_COLUMNS_STORAGE_KEY = "erpify:users-columns";

const COLUMNS_SEPARATOR = ",";

/** Serialize the visible set to a CSV string for `useStoredPreference` (string-only). */
export function serializeColumns(keys: readonly UserColumnKey[]): string {
  return keys.join(COLUMNS_SEPARATOR);
}

/** Parse the CSV back to keys; the pinned `email` column is always present. */
export function parseColumns(raw: string): UserColumnKey[] {
  const parts = raw
    .split(COLUMNS_SEPARATOR)
    .filter((p): p is UserColumnKey => (USER_COLUMN_KEYS as readonly string[]).includes(p));
  return parts.includes("email") ? parts : ["email", ...parts];
}

/** Validate a stored CSV value: every token must be a known column key. */
export function isStoredColumnsValue(v: string): v is string {
  return (
    v.length > 0 &&
    v.split(COLUMNS_SEPARATOR).every((p) => (USER_COLUMN_KEYS as readonly string[]).includes(p))
  );
}
