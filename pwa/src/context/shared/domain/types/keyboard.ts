/**
 * Centralised `KeyboardEvent.key` values used across the PWA. The raw strings
 * (`"ArrowUp"`, `"Enter"`, `" "`, …) are spelled here once so call sites
 * compare against {@link KeyboardKey.ARROW_UP} rather than a free-form literal
 * — typos are caught at compile time and refactors stay safe.
 *
 * The matching TypeScript type is derived via
 * `(typeof KeyboardKey)[keyof typeof KeyboardKey]` so adding / renaming a value
 * forces every call site to update.
 */
export const KeyboardKey = {
  ARROW_UP: "ArrowUp",
  ARROW_DOWN: "ArrowDown",
  ENTER: "Enter",
  ESCAPE: "Escape",
  SLASH: "/",
  SPACE: " ",
} as const;
export type KeyboardKey = (typeof KeyboardKey)[keyof typeof KeyboardKey];
