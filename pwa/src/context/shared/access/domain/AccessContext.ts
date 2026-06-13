/** Which surface the session is operating in (multi-portal future). */
export const AccessContext = {
  BACKOFFICE: "BACKOFFICE",
  CUSTOMER_PORTAL: "CUSTOMER_PORTAL",
  EMPLOYEE_PORTAL: "EMPLOYEE_PORTAL",
  SUPPLIER_PORTAL: "SUPPLIER_PORTAL",
} as const;
export type AccessContext = (typeof AccessContext)[keyof typeof AccessContext];
