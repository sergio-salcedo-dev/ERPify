import type { ReactNode } from "react";
import { AuthContext, AuthStatus } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Permission, type HeldPermission } from "@/context/shared/access/domain/Permission";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Role } from "@/context/shared/access/domain/Role";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import type { Session } from "@/context/shared/access/domain/Session";

/** A signed-in back-office session holding exactly `permissions`. */
export function sessionWith(permissions: HeldPermission[]): Session {
  return {
    user: {
      id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
      email: "operator@erpify.test",
      status: UserStatus.ACTIVE,
      roles: [Role.ADMIN],
      permissions,
    },
    roles: [Role.ADMIN],
    permissions,
    context: AccessContext.BACKOFFICE,
  };
}

export const BANK_WRITER = sessionWith([Permission.BANK_WRITE]);
export const BANK_READER = sessionWith([]);

/**
 * Supplies a ready-made session to a page that gates on `<Can>`, without the `/me` probe the real
 * provider would run through the container.
 */
export function WithSession({
  session,
  children,
}: Readonly<{ session: Session | null; children: ReactNode }>) {
  const value = {
    status: session === null ? AuthStatus.UNAUTHENTICATED : AuthStatus.AUTHENTICATED,
    session,
    login: () => Promise.resolve(session),
    refresh: () => Promise.resolve(session),
    logout: () => Promise.resolve(),
    override: () => undefined,
  };
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
