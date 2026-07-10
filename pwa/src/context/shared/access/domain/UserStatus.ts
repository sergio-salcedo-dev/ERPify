/**
 * Access-level user status — an authentication/authorization primitive, NOT a
 * business attribute. Gates the auth layer: only ACTIVE authenticates; INVITED
 * (never signed in), SUSPENDED (temporarily barred) and DEACTIVATED (permanently
 * off) are all hard stops. Business statuses (CustomerStatus/EmployeeStatus) are
 * separate even when names overlap; never reuse this enum for them. See the IAM
 * design spec.
 */
export const UserStatus = {
  INVITED: "INVITED",
  ACTIVE: "ACTIVE",
  SUSPENDED: "SUSPENDED",
  DEACTIVATED: "DEACTIVATED",
} as const;
export type UserStatus = (typeof UserStatus)[keyof typeof UserStatus];
