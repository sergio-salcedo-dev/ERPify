/**
 * Centralised theme identifiers for the PWA. The literal strings `"light"`,
 * `"dark"` and `"system"` are spelled here once so call sites compare against
 * {@link Theme.DARK} rather than a free-form literal — typos are caught at
 * compile time and refactors stay safe. The values intentionally match the
 * strings `next-themes` reads from / writes to storage and applies as the
 * `<html>` class, so these constants flow straight into its API.
 *
 * `THEME_STORAGE_KEY` mirrors the `erpify:sidebar-open` convention used by the
 * back-office layout, keeping every persisted UI preference under one
 * `erpify:` namespace.
 *
 * The matching TypeScript type is derived via
 * `(typeof Theme)[keyof typeof Theme]` so adding / renaming a value forces
 * every call site to update.
 */
export const Theme = {
  LIGHT: "light",
  DARK: "dark",
  SYSTEM: "system",
} as const;
export type Theme = (typeof Theme)[keyof typeof Theme];

/** localStorage key next-themes persists the chosen theme under. */
export const THEME_STORAGE_KEY = "erpify:theme";
