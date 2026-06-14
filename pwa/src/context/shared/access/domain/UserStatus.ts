/**
 * Access-level user status — an authentication/authorization primitive, NOT a
 * business attribute. Gates the auth layer (BLOCKED ⇒ hard stop). Business
 * statuses (CustomerStatus/EmployeeStatus) are separate even when names overlap;
 * never reuse this enum for them. See the IAM design spec.
 */
export const UserStatus = {
  ACTIVE: "ACTIVE",
  BLOCKED: "BLOCKED",
  PENDING: "PENDING",
} as const;
export type UserStatus = (typeof UserStatus)[keyof typeof UserStatus];
