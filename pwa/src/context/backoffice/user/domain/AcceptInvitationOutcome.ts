import type { ProblemViolation } from "@/context/shared/error/domain/ProblemDetails";

/**
 * The `ProblemDetails.type` discriminators the accept-invitation adapter routes
 * on. A dead token (used, revoked, expired, already-accepted, non-existent) all
 * collapse to the single `invalid-token` type — the type, never the status
 * alone, decides the outcome, so a 400 that is not `invalid-token` is not
 * mistaken for a dead link.
 */
export const AcceptInvitationProblemType = {
  INVALID_TOKEN: "invalid-token",
  VALIDATION_FAILED: "validation-failed",
} as const;
export type AcceptInvitationProblemType =
  (typeof AcceptInvitationProblemType)[keyof typeof AcceptInvitationProblemType];

/**
 * The kinds of an {@link AcceptInvitationOutcome}. Named constants keep the
 * discriminant grep-able and out of magic strings at both the adapter and the
 * screen.
 */
export const AcceptInvitationOutcomeKind = {
  ACCEPTED: "accepted",
  INVALID_LINK: "invalid-link",
  VALIDATION_ERROR: "validation-error",
} as const;
export type AcceptInvitationOutcomeKind =
  (typeof AcceptInvitationOutcomeKind)[keyof typeof AcceptInvitationOutcomeKind];

/**
 * The typed result of accepting an invitation, mapped from the HTTP response by
 * the adapter so the UI never inspects a status code.
 *
 *  - `accepted` — 204; the httpOnly session cookie is set server-side.
 *  - `invalid-link` — the token is dead; the screen shows the neutral access
 *    wall. Deliberately indistinguishable across every dead-token reason.
 *  - `validation-error` — the password failed the server policy (422); the
 *    per-field violations surface on the form.
 *
 * Any other HTTP error (origin/CSRF rejection, transport fault) is NOT an accept
 * outcome and propagates so the form shows a generic, retryable error.
 */
export type AcceptInvitationOutcome =
  | { kind: typeof AcceptInvitationOutcomeKind.ACCEPTED }
  | { kind: typeof AcceptInvitationOutcomeKind.INVALID_LINK }
  | {
      kind: typeof AcceptInvitationOutcomeKind.VALIDATION_ERROR;
      violations: ProblemViolation[];
    };
