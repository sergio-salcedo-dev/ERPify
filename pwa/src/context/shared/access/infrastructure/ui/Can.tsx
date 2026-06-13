"use client";

import type { ReactNode } from "react";
import { useSession } from "../../application/useSession";
import { authorize } from "../../domain/authorize";
import { UserStatus } from "../../domain/UserStatus";
import type { Permission } from "../../domain/Permission";
import type { Role } from "../../domain/Role";

interface CanProps {
  permission?: Permission;
  role?: Role;
  /** Optional element shown when denied (default: nothing — hide, never error). */
  fallback?: ReactNode;
  children: ReactNode;
}

/**
 * UI guard: renders children only when the session passes the permission/role
 * check; otherwise renders `fallback` (default null). A missing permission HIDES
 * the action — it never shows an error. A single `useSession` call keeps the
 * hook order stable regardless of which props are supplied.
 */
export function Can({ permission, role, fallback = null, children }: Readonly<CanProps>) {
  const { session } = useSession();
  const allowedByPermission =
    permission === undefined || (session !== null && authorize(session, permission));
  const allowedByRole =
    role === undefined ||
    (session !== null &&
      session.user.status === UserStatus.ACTIVE &&
      session.roles.includes(role));
  return allowedByPermission && allowedByRole ? <>{children}</> : <>{fallback}</>;
}
