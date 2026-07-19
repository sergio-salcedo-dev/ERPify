/** Wildcard: a session holding it satisfies every permission check. */
export const PERMISSION_WILDCARD = "*" as const;

/**
 * The permissions this console gates on, byte-identical to the strings the API declares in its authorization
 * policy and enforces with `#[IsGranted]`. Nothing derives one side from the other, so a mismatch is silent —
 * a gate that never opens, with no error anywhere. Copy the string; never re-spell it.
 *
 * A deliberate subset of the API's vocabulary: it also publishes bank, bankAccount and audit permissions, but
 * nothing here gates on them, and `/me` entries the client does not declare are discarded on the way in.
 *
 * Identity administration is not CRUD: the capabilities are invite, change-status, change-roles, and the
 * separate GDPR erase — each its own permission, none of them a generic write or delete.
 */
export const Permission = {
  USERS_READ: "users.read",
  USERS_INVITE: "users.invite",
  USERS_CHANGE_STATUS: "users.changeStatus",
  USERS_CHANGE_ROLES: "users.changeRoles",
  USERS_ERASE: "users.erase",
} as const;
export type Permission = (typeof Permission)[keyof typeof Permission];
/** A held permission may be a concrete permission or the wildcard. */
export type HeldPermission = Permission | typeof PERMISSION_WILDCARD;
export const ALL_PERMISSIONS: readonly Permission[] = Object.values(Permission);
