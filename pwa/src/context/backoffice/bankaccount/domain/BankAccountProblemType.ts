/**
 * RFC 9457 `type` values the bank-account API emits (wire contract — see
 * `docs/api-error-contract.md`). The UI maps these to typed recovery actions in
 * the persistent mutation-error surface; unmapped types get no action. These
 * MUST stay in lock-step with the API domain-exception slugs
 * (`BankAccountNotFoundException`, `BankAccountNotClosedException`, `BankAccountHasLiveEvidence`,
 * `EvidenceDocumentUnavailable`, `EvidenceRetentionMismatch`, `EvidenceAlreadyAttached`).
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
  /**
   * The account still holds evidence whose document is not erased (409, `liveDocuments` counts it).
   * Deletion waits until each document is erased through its own route.
   */
  HAS_LIVE_EVIDENCE: "bank-account-has-live-evidence",
  /** The document does not exist, is erased or is quarantined (422). */
  EVIDENCE_UNAVAILABLE: "bank-account-evidence-unavailable",
  /** The document was filed under retention terms other than the kind requires (422). */
  EVIDENCE_RETENTION_MISMATCH: "bank-account-evidence-retention-mismatch",
  /** The document is already attached to this account (409). */
  EVIDENCE_ALREADY_ATTACHED: "bank-account-evidence-already-attached",
  /** The document is already the evidence of another account: a document proves one account (409). */
  EVIDENCE_ATTACHED_ELSEWHERE: "bank-account-evidence-attached-elsewhere",
} as const;

export type BankAccountProblemType =
  (typeof BankAccountProblemType)[keyof typeof BankAccountProblemType];
