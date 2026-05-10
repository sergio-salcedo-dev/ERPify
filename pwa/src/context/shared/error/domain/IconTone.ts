/**
 * Visual tones available for the circular icon badge in `<ErrorScreen>`.
 *
 * Lives in the error module's domain layer (no React, no Tailwind) — the
 * Tailwind class map that turns each tone into concrete background and
 * foreground colors is an implementation detail of the
 * `infrastructure/ui` adapter.
 *
 * The matching TypeScript type is derived via
 * `(typeof IconTone)[keyof typeof IconTone]` so adding / renaming a
 * tone forces every call site to update.
 */
export const IconTone = {
  /** Brand / informational. Default for 404, 401 and similar. */
  PRIMARY: "primary",
  /** Hard failure. Use for 500 and root crashes. */
  DESTRUCTIVE: "destructive",
  /** Recoverable / advisory. Use for 403, 503, 429. */
  WARNING: "warning",
  /** Neutral / disabled state. Use for offline. */
  MUTED: "muted",
} as const;
export type IconTone = (typeof IconTone)[keyof typeof IconTone];
