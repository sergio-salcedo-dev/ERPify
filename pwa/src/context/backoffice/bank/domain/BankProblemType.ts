/**
 * RFC 9457 `type` values the bank API emits (wire contract — see
 * `docs/api-error-contract.md`). The UI maps these to typed recovery actions
 * in the persistent mutation-error surface; unmapped types get no action.
 */
export const BankProblemType = {
  /** The bank no longer exists (stale row) — recoverable with a list refresh. */
  NOT_FOUND: "bank-not-found",
  /** The bank still has associated accounts (409) — recovery is a "View accounts" action on the mutation-error surface (a single delete deep-links; a bulk delete orients to the per-row guard). */
  IN_USE: "bank-in-use",
} as const;

export type BankProblemType = (typeof BankProblemType)[keyof typeof BankProblemType];
