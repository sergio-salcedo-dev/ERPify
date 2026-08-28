/**
 * The `ProblemDetails.type` discriminators the recovery-redeem adapter routes on.
 *
 * There is exactly one failure type on the secret itself. Malformed, unknown, expired,
 * already spent and budget-exhausted all answer `invalid-token` with the same body, by
 * design on the API side: telling the two apart would tell whoever is guessing which half of
 * a presented secret was right. The client therefore has nothing finer to branch on, and
 * inventing finer copy would describe a distinction the wire does not carry.
 *
 * The two 403 types are the account's state, not the secret's, and each maps to an existing
 * access wall. A generic `forbidden` 403 (an origin or CSRF rejection) is neither and is
 * rethrown — the type, never the status alone, decides the outcome.
 */
export const RedeemRecoverySecretProblemType = {
  INVALID_TOKEN: "invalid-token",
  ACCOUNT_SUSPENDED: "account-suspended",
  ACCOUNT_DEACTIVATED: "account-deactivated",
} as const;
export type RedeemRecoverySecretProblemType =
  (typeof RedeemRecoverySecretProblemType)[keyof typeof RedeemRecoverySecretProblemType];

/** The kinds of a {@link RedeemRecoverySecretOutcome}, kept out of magic strings at both ends. */
export const RedeemRecoverySecretOutcomeKind = {
  REDEEMED: "redeemed",
  INVALID_SECRET: "invalid-secret",
  SUSPENDED: "suspended",
  DEACTIVATED: "deactivated",
} as const;
export type RedeemRecoverySecretOutcomeKind =
  (typeof RedeemRecoverySecretOutcomeKind)[keyof typeof RedeemRecoverySecretOutcomeKind];

/**
 * The typed result of redeeming a recovery secret, mapped from the HTTP response by the
 * adapter so the screen never inspects a status code.
 *
 *  - `redeemed` — 204; the httpOnly session cookie is set server-side and the user is signed
 *    in on this device.
 *  - `invalid-secret` — the single opaque failure; the screen restates one message and lets
 *    the user try again, because a typo and a spent secret are indistinguishable here and a
 *    typo is the recoverable one.
 *  - `suspended` / `deactivated` — 403; the account cannot be recovered this way at all, so
 *    the screen shows the matching terminal wall rather than inviting a retry.
 *
 * Any other HTTP error (origin/CSRF rejection, an unavailable session store, a transport
 * fault) is NOT a redeem outcome and propagates, so the form shows a neutral retryable error.
 */
export type RedeemRecoverySecretOutcome =
  | { kind: typeof RedeemRecoverySecretOutcomeKind.REDEEMED }
  | { kind: typeof RedeemRecoverySecretOutcomeKind.INVALID_SECRET }
  | { kind: typeof RedeemRecoverySecretOutcomeKind.SUSPENDED }
  | { kind: typeof RedeemRecoverySecretOutcomeKind.DEACTIVATED };
