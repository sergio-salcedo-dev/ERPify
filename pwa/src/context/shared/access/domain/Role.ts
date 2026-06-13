/** Coarse-grained organizational roles (RBAC). Not business state. */
export const Role = {
  SUPER_ADMIN: "SUPER_ADMIN",
  ADMIN: "ADMIN",
  EMPLOYEE: "EMPLOYEE",
  CUSTOMER: "CUSTOMER",
  SUPPLIER: "SUPPLIER",
} as const;
export type Role = (typeof Role)[keyof typeof Role];
export const ALL_ROLES: readonly Role[] = Object.values(Role);
