import type { Session } from "@/context/shared/access/domain/Session";

/**
 * Whether the signed-in operator is looking at their own identity. The API refuses a role change, a status
 * change and an erasure an administrator aims at themselves (409 `self-role-change-forbidden`,
 * `self-status-change-forbidden`, `self-erasure-forbidden`), so the detail offers none of those controls there. UUID hex is case-insensitive, and the route id is spelled by the
 * address bar, so the comparison ignores case like the API's does.
 */
export function isOwnIdentity(session: Session | null, userId: string): boolean {
  return session !== null && session.user.id.toLowerCase() === userId.toLowerCase();
}
