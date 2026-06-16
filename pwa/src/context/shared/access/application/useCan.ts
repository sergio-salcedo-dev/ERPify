"use client";

import { useSession } from "./useSession";
import { authorize } from "../domain/authorize";
import type { Permission } from "../domain/Permission";
import type { Role } from "../domain/Role";
import { UserStatus } from "../domain/UserStatus";

/** True when the current session is allowed `permission` (false if no session). */
export function useCan(permission: Permission): boolean {
  const { session } = useSession();
  return session ? authorize(session, permission) : false;
}

/** True when the current ACTIVE session holds `role`. */
export function useCanRole(role: Role): boolean {
  const { session } = useSession();
  if (session?.user.status !== UserStatus.ACTIVE) return false;
  return session.roles.includes(role);
}
