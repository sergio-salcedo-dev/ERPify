import type { Identity } from "./Identity";
import type { HeldPermission } from "./Permission";
import type { AccessContext } from "./AccessContext";
import type { BusinessContext } from "./BusinessContext";

/**
 * The frontend session model. The `AuthProvider` fills it from the gated
 * `/me` endpoint (the httpOnly session cookie is the source of truth); the
 * dev switcher may mutate a live one.
 *
 * `roles` mirrors {@link Identity.roles}: the backend role names verbatim as a
 * `string[]` (see {@link Identity}).
 */
export interface Session {
  user: Identity;
  roles: string[];
  permissions: HeldPermission[];
  context: AccessContext;
  business?: BusinessContext;
}
