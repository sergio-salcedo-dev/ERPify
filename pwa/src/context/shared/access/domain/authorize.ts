import type { Session } from "./Session";
import type { Permission, HeldPermission } from "./Permission";
import { PERMISSION_WILDCARD } from "./Permission";
import { UserStatus } from "./UserStatus";

/** True if the held set satisfies `permission` (the wildcard satisfies all). */
export function hasPermission(held: readonly HeldPermission[], permission: Permission): boolean {
  return held.includes(PERMISSION_WILDCARD) || held.includes(permission);
}

/**
 * Role↔context validity. v1: every role is valid in BACKOFFICE; the seam exists
 * so v2 can restrict (e.g. CUSTOMER only in CUSTOMER_PORTAL). Always true now.
 */
export function roleContextValid(_session: Session): boolean {
  return true;
}

/**
 * Domain-policy layer (ABAC-lite) — the v2 plug-in point (see
 * AccessPolicyRegistry). Stubbed to allow this iteration.
 */
export function domainPolicyAllow(_session: Session, _permission: Permission): boolean {
  return true;
}

/**
 * The Authorization Equation:
 *   ALLOW = status===ACTIVE AND hasPermission AND roleContextValid AND domainPolicyAllow
 */
export function authorize(session: Session, permission: Permission): boolean {
  return (
    session.user.status === UserStatus.ACTIVE &&
    hasPermission(session.permissions, permission) &&
    roleContextValid(session) &&
    domainPolicyAllow(session, permission)
  );
}
