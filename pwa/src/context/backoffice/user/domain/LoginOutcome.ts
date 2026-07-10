/**
 * The `ProblemDetails.type` discriminators the login adapter routes on. Two 403
 * responses share the status but mean different things — the type, never the
 * status alone, decides which access wall the user sees.
 */
export const LoginProblemType = {
  ACCOUNT_SUSPENDED: "account-suspended",
  FORBIDDEN: "forbidden",
} as const;
export type LoginProblemType = (typeof LoginProblemType)[keyof typeof LoginProblemType];

/**
 * The kinds of a {@link LoginOutcome}. Named constants keep the discriminant
 * grep-able and out of magic strings at both the adapter and the form.
 */
export const LoginOutcomeKind = {
  AUTHENTICATED: "authenticated",
  INVALID_CREDENTIALS: "invalid-credentials",
  SUSPENDED: "suspended",
  DEACTIVATED: "deactivated",
} as const;
export type LoginOutcomeKind = (typeof LoginOutcomeKind)[keyof typeof LoginOutcomeKind];

/**
 * The typed result of a sign-in attempt, mapped from the HTTP response by the
 * adapter so the UI never inspects a status code. Neutrality is preserved:
 * `invalid-credentials` collapses wrong-password, unknown-email and invited into
 * one indistinguishable outcome.
 */
export type LoginOutcome =
  | { kind: typeof LoginOutcomeKind.AUTHENTICATED }
  | { kind: typeof LoginOutcomeKind.INVALID_CREDENTIALS }
  | { kind: typeof LoginOutcomeKind.SUSPENDED }
  | { kind: typeof LoginOutcomeKind.DEACTIVATED };
