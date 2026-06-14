/** Wildcard: a session holding it satisfies every permission check. */
export const PERMISSION_WILDCARD = "*" as const;

/** Fine-grained, additive permissions. Extend as modules land. */
export const Permission = {
  USERS_READ: "users.read",
  USERS_WRITE: "users.write",
  USERS_DELETE: "users.delete",
  PROJECTS_READ: "projects.read",
  PROJECTS_WRITE: "projects.write",
  INVOICES_READ: "invoices.read",
  INVOICES_WRITE: "invoices.write",
} as const;
export type Permission = (typeof Permission)[keyof typeof Permission];
/** A held permission may be a concrete permission or the wildcard. */
export type HeldPermission = Permission | typeof PERMISSION_WILDCARD;
export const ALL_PERMISSIONS: readonly Permission[] = Object.values(Permission);
