import type { ProblemViolation } from "@/context/shared/error/domain/ProblemDetails";

/**
 * The `ProblemDetails.type` discriminators the reset-password adapter routes on.
 * A dead token (used, expired, unknown) collapses to the single `invalid-token`
 * type; a suspended or forbidden account each carries its own type. The type,
 * never the status alone, decides the outcome — two 403s carry different meaning.
 */
export const ResetPasswordProblemType = {
  INVALID_TOKEN: "invalid-token",
  ACCOUNT_SUSPENDED: "account-suspended",
  FORBIDDEN: "forbidden",
  VALIDATION_FAILED: "validation-failed",
} as const;
export type ResetPasswordProblemType =
  (typeof ResetPasswordProblemType)[keyof typeof ResetPasswordProblemType];

/**
 * The kinds of a {@link ResetPasswordOutcome}. Named constants keep the
 * discriminant grep-able and out of magic strings at both the adapter and the
 * screen.
 */
export const ResetPasswordOutcomeKind = {
  RESET: "reset",
  INVALID_LINK: "invalid-link",
  SUSPENDED: "suspended",
  DEACTIVATED: "deactivated",
  VALIDATION_ERROR: "validation-error",
} as const;
export type ResetPasswordOutcomeKind =
  (typeof ResetPasswordOutcomeKind)[keyof typeof ResetPasswordOutcomeKind];

/**
 * The typed result of resetting a password, mapped from the HTTP response by the
 * adapter so the UI never inspects a status code.
 *
 *  - `reset` — 204; the httpOnly session cookie is set server-side (the user is
 *    now signed in on this device).
 *  - `invalid-link` — the token is dead; the screen shows the neutral access
 *    wall. Deliberately indistinguishable across every dead-token reason.
 *  - `suspended` / `deactivated` — 403; the account cannot be recovered this way
 *    and the screen shows the matching access wall.
 *  - `validation-error` — the password failed the server policy (422); the
 *    per-field violations surface on the form.
 *
 * Any other HTTP error (origin/CSRF rejection, transport fault) is NOT a reset
 * outcome and propagates so the form shows a generic, retryable error.
 */
export type ResetPasswordOutcome =
  | { kind: typeof ResetPasswordOutcomeKind.RESET }
  | { kind: typeof ResetPasswordOutcomeKind.INVALID_LINK }
  | { kind: typeof ResetPasswordOutcomeKind.SUSPENDED }
  | { kind: typeof ResetPasswordOutcomeKind.DEACTIVATED }
  | {
      kind: typeof ResetPasswordOutcomeKind.VALIDATION_ERROR;
      violations: ProblemViolation[];
    };
