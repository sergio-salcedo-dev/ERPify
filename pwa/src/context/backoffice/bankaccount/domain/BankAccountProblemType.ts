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
  /** The account still holds evidence whose document is not erased, so it cannot be removed (409). */
  HAS_LIVE_EVIDENCE: "bank-account-has-live-evidence",
  /** The document to attach does not exist (422). */
  EVIDENCE_DOCUMENT_NOT_FOUND: "bank-account-evidence-document-not-found",
  /** The document to attach was filed under other retention terms than its kind fixes (422). */
  EVIDENCE_RETENTION_MISMATCH: "bank-account-evidence-retention-mismatch",
  /** The document to attach has been erased (409). */
  EVIDENCE_DOCUMENT_ERASED: "bank-account-evidence-document-erased",
  /** The account already holds that document (409). */
  EVIDENCE_ALREADY_ATTACHED: "bank-account-evidence-already-attached",
} as const;

export type BankAccountProblemType =
  (typeof BankAccountProblemType)[keyof typeof BankAccountProblemType];
