import type { UserStatus } from "./UserStatus";
import type { HeldPermission } from "./Permission";

/**
 * The identity carried in a session. A projection of the User aggregate limited
 * to what the access layer needs — never the password, never audit fields a
 * client may not see.
 *
 * `roles` holds the backend role names verbatim (e.g. `"ADMIN"`,
 * `"AUDIT_READER"`, `"VIEWER"`). It is a `string[]`, not `Role[]`: the backend
 * vocabulary is a superset of the frontend {@link Role} enum, and forcing an
 * invalid cast would lie about roles the enum does not yet model. Consumers that
 * query a specific role still pass a typed {@link Role}; `string[].includes()`
 * accepts it.
 */
export interface Identity {
  id: string;
  email: string;
  status: UserStatus;
  roles: string[];
  permissions: HeldPermission[];
}
