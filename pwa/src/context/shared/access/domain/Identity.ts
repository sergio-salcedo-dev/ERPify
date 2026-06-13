import type { UserStatus } from "./UserStatus";
import type { Role } from "./Role";
import type { HeldPermission } from "./Permission";

/**
 * The identity carried in a session. A projection of the User aggregate limited
 * to what the access layer needs — never the password, never audit fields a
 * client may not see.
 */
export interface Identity {
  id: string;
  email: string;
  status: UserStatus;
  roles: Role[];
  permissions: HeldPermission[];
}
