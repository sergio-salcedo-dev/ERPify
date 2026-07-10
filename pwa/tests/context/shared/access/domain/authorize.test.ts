import { describe, it, expect } from "vitest";
import { authorize, hasPermission } from "@/context/shared/access/domain/authorize";
import type { Session } from "@/context/shared/access/domain/Session";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Role } from "@/context/shared/access/domain/Role";
import { Permission, PERMISSION_WILDCARD } from "@/context/shared/access/domain/Permission";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";

function session(overrides: Partial<Session> = {}): Session {
  const base: Session = {
    user: {
      id: "u1",
      email: "a@b.com",
      status: UserStatus.ACTIVE,
      roles: [Role.ADMIN],
      permissions: [PERMISSION_WILDCARD],
    },
    roles: [Role.ADMIN],
    permissions: [PERMISSION_WILDCARD],
    context: AccessContext.BACKOFFICE,
  };
  return { ...base, ...overrides };
}

describe("authorize", () => {
  it("allows when ACTIVE and holds the wildcard", () => {
    expect(authorize(session(), Permission.USERS_WRITE)).toBe(true);
  });

  it("denies when status is not ACTIVE, even with the wildcard", () => {
    expect(
      authorize(
        session({ user: { ...session().user, status: UserStatus.SUSPENDED } }),
        Permission.USERS_READ,
      ),
    ).toBe(false);
  });

  it("allows a concrete permission and denies a missing one", () => {
    const s = session({ permissions: [Permission.USERS_READ] });
    expect(authorize(s, Permission.USERS_READ)).toBe(true);
    expect(authorize(s, Permission.USERS_DELETE)).toBe(false);
  });

  it("hasPermission honors the wildcard", () => {
    expect(hasPermission([PERMISSION_WILDCARD], Permission.INVOICES_WRITE)).toBe(true);
    expect(hasPermission([Permission.INVOICES_READ], Permission.INVOICES_WRITE)).toBe(false);
  });
});
