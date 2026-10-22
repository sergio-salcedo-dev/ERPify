/**
 * RFC 9457 `type` values the API raises from shared infrastructure rather than from one bounded
 * context (wire contract — see `docs/api-error-contract.md`).
 *
 * They live beside {@link ProblemDetails} instead of in a per-entity `*ProblemType` because a single
 * API class answers for every aggregate: `concurrent-unique-write` already covers `bank`,
 * `bank-account` and `identity-user`, so an entity-scoped copy would fork one wire value into three
 * literals that drift independently.
 */
export const SharedProblemType = {
  /**
   * A unique index refused the write because a competing request committed the same value first
   * (409). The server's own pre-check is a SELECT and the write an INSERT, and a failed commit
   * closes the unit of work, so it cannot name the field — which is why this type carries no
   * `violations`. A retry is the remedy the contract documents: the winning row is committed by
   * then, so the pre-check sees it and answers with the precise 422 this response could not.
   */
  CONCURRENT_UNIQUE_WRITE: "concurrent-unique-write",
} as const;

export type SharedProblemType = (typeof SharedProblemType)[keyof typeof SharedProblemType];
