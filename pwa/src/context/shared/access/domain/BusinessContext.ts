/** Business-level states — declared as a v2 seam, NOT used this iteration. */
export const EmployeeStatus = {
  ACTIVE: "ACTIVE",
  ON_LEAVE: "ON_LEAVE",
  TERMINATED: "TERMINATED",
} as const;
export type EmployeeStatus = (typeof EmployeeStatus)[keyof typeof EmployeeStatus];

export const CustomerStatus = {
  ACTIVE: "ACTIVE",
  SUSPENDED: "SUSPENDED",
  BLOCKED_FINANCIAL: "BLOCKED_FINANCIAL",
} as const;
export type CustomerStatus = (typeof CustomerStatus)[keyof typeof CustomerStatus];

/** Optional business context attached to a session for future ABAC policies. */
export interface BusinessContext {
  employeeStatus?: EmployeeStatus;
  customerStatus?: CustomerStatus;
}
