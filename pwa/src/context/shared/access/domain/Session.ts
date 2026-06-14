import type { Identity } from "./Identity";
import type { Role } from "./Role";
import type { HeldPermission } from "./Permission";
import type { AccessContext } from "./AccessContext";
import type { BusinessContext } from "./BusinessContext";

/**
 * The frontend session model. Mocked this iteration; an `AuthProvider` seeds it
 * and the dev switcher mutates it. A real API session later fills the same shape
 * with no consumer change.
 */
export interface Session {
  user: Identity;
  roles: Role[];
  permissions: HeldPermission[];
  context: AccessContext;
  business?: BusinessContext;
}
