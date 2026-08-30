import type { Session } from "@/context/shared/access/domain/Session";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";

/**
 * What a confirmed `/me` probe hands back to an `(auth)` screen after its 204.
 *
 * These screens branch on whether `login()` resolved a session at all, so the shape matters
 * less than the fact that there is one — a mock resolving `undefined` would put every success
 * path through the unconfirmed-probe branch and pass for the wrong reason.
 */
export const SIGNED_IN_SESSION: Session = {
  user: {
    id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
    email: "ada@erpify.test",
    status: UserStatus.ACTIVE,
    roles: ["ADMIN"],
    permissions: [],
  },
  roles: ["ADMIN"],
  permissions: [],
  context: AccessContext.BACKOFFICE,
};
