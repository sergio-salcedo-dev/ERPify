/**
 * Coarse-grained organizational roles (RBAC). Not business state. The member
 * set is byte-identical to the API's `Role` enum (`api/src/Iam/Identity/Domain/
 * Enum/Role.php`): the wire carries the enum `.value`, so these strings ARE the
 * contract — a divergence silently breaks role rendering and the dev switcher.
 */
export const Role = {
  VIEWER: "VIEWER",
  EDITOR: "EDITOR",
  MANAGER: "MANAGER",
  ADMIN: "ADMIN",
  AUDIT_READER: "AUDIT_READER",
} as const;
export type Role = (typeof Role)[keyof typeof Role];
export const ALL_ROLES: readonly Role[] = Object.values(Role);
