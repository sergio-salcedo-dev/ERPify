import { createColumnPreference } from "@/lib/columnPreference";

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

const preference = createColumnPreference<UserColumnKey>({
  allKeys: USER_COLUMN_KEYS,
  pinned: PINNED_COLUMNS,
});

export const serializeColumns = preference.serialize;
export const parseColumns = preference.parse;
export const isStoredColumnsValue = preference.isStoredValue;
