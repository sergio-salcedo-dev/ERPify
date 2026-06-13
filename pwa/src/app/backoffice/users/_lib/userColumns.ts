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
export function isUserColumnKeyArray(v: unknown): v is UserColumnKey[] {
  return Array.isArray(v) && v.every((x) => (USER_COLUMN_KEYS as readonly string[]).includes(x));
}
