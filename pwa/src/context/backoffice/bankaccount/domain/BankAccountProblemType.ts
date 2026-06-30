/**
 * RFC 9457 `type` values the bank-account API emits (wire contract — see
 * `docs/api-error-contract.md`). The UI maps these to typed recovery actions in
 * the persistent mutation-error surface; unmapped types get no action. These
 * MUST stay in lock-step with the API domain-exception slugs
 * (`BankAccountNotFoundException` / `BankAccountNotClosedException`).
 */
export const BankAccountProblemType = {
  /** The account no longer exists (stale row) — recoverable with a list refresh. */
  NOT_FOUND: "bank-account-not-found",
  /**
   * The account is not `CLOSED`, so it cannot be deleted (409) — recovery is an
   * "Edit account" action so the user can close it first, mirroring Bank's
   * `in-use` deep-link.
   */
  NOT_CLOSED: "bank-account-not-closed",
} as const;

export type BankAccountProblemType =
  (typeof BankAccountProblemType)[keyof typeof BankAccountProblemType];
