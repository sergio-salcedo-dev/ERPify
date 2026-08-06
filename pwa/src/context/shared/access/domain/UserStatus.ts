/**
 * Access-level user status — an authentication/authorization primitive, NOT a
 * business attribute. Gates the auth layer: only ACTIVE authenticates; INVITED
 * (never signed in), REVOKED (invitation withdrawn before it was accepted),
 * SUSPENDED (temporarily barred) and DEACTIVATED (permanently off) are all hard
 * stops. REVOKED and DEACTIVATED are both terminal but answer different questions
 * about a person — a withdrawn invitation is not a retired member — so they stay
 * distinct here rather than collapsing into one "off". Business statuses
 * (CustomerStatus/EmployeeStatus) are separate even when names overlap; never
 * reuse this enum for them. See the IAM design spec.
 */
export const UserStatus = {
  INVITED: "INVITED",
  ACTIVE: "ACTIVE",
  SUSPENDED: "SUSPENDED",
  DEACTIVATED: "DEACTIVATED",
  REVOKED: "REVOKED",
} as const;
export type UserStatus = (typeof UserStatus)[keyof typeof UserStatus];
