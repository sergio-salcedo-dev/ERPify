import { describe, it, expect, beforeEach } from "vitest";
import { useContext } from "react";
import { renderHook, act, waitFor } from "@testing-library/react";
import {
  AuthProvider,
  AuthContext,
  AuthStatus,
  type AuthContextValue,
} from "@/context/shared/access/infrastructure/ui/AuthProvider";
import type { Identity } from "@/context/shared/access/domain/Identity";
import type { Session } from "@/context/shared/access/domain/Session";
import { Role } from "@/context/shared/access/domain/Role";
import { Permission, PERMISSION_WILDCARD } from "@/context/shared/access/domain/Permission";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";

const STORAGE_KEY = "erpify:session";
const SEED_EMAIL = "admin@erpify.dev";

// Consume the raw context so the test can drive login/logout/override without
// depending on the useSession hook module under test in isolation.
function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("missing provider");
  return ctx;
}

function renderAuth() {
  return renderHook<AuthContextValue, void>(() => useAuth(), { wrapper: AuthProvider });
}

const EMPLOYEE: Identity = {
  id: "00000000-0000-7000-8000-0000000000aa",
  email: "stored@erpify.dev",
  status: UserStatus.ACTIVE,
  roles: [Role.EMPLOYEE],
  permissions: [Permission.USERS_READ],
};

function storedSession(): Session {
  return {
    user: EMPLOYEE,
    roles: EMPLOYEE.roles,
    permissions: EMPLOYEE.permissions,
    context: AccessContext.EMPLOYEE_PORTAL,
  };
}

describe("AuthProvider", () => {
  beforeEach(() => {
    globalThis.localStorage.clear();
  });

  it("hydrates to the seeded admin when storage is empty", async () => {
    const { result } = renderAuth();

    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));
    expect(result.current.session?.user.email).toBe(SEED_EMAIL);
    expect(result.current.session?.permissions).toContain(PERMISSION_WILDCARD);
    expect(result.current.session?.context).toBe(AccessContext.BACKOFFICE);
  });

  it("restores a valid stored session over the seed", async () => {
    globalThis.localStorage.setItem(STORAGE_KEY, JSON.stringify(storedSession()));

    const { result } = renderAuth();

    await waitFor(() => expect(result.current.session).not.toBeNull());
    expect(result.current.session?.user.email).toBe("stored@erpify.dev");
    expect(result.current.session?.context).toBe(AccessContext.EMPLOYEE_PORTAL);
    expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
  });

  it.each([
    ["unparseable JSON", "{not valid json"],
    ["JSON null", "null"],
    ["a bare string", '"just a string"'],
    ["an object with no user", "{}"],
    ["a non-object user", '{"user":42}'],
    [
      "a user with a non-string id",
      JSON.stringify({
        user: { id: 1, email: "x", status: UserStatus.ACTIVE, roles: [], permissions: [] },
        roles: [],
        permissions: [],
        context: AccessContext.BACKOFFICE,
      }),
    ],
    [
      "an unknown user status",
      JSON.stringify({
        user: { id: "x", email: "x", status: "GHOST", roles: [], permissions: [] },
        roles: [],
        permissions: [],
        context: AccessContext.BACKOFFICE,
      }),
    ],
    [
      "missing top-level arrays/context",
      JSON.stringify({
        user: { id: "x", email: "x", status: UserStatus.ACTIVE, roles: [], permissions: [] },
      }),
    ],
  ])("falls back to the seed when the stored payload is %s", async (_label, raw) => {
    globalThis.localStorage.setItem(STORAGE_KEY, raw);

    const { result } = renderAuth();

    await waitFor(() => expect(result.current.session).not.toBeNull());
    expect(result.current.session?.user.email).toBe(SEED_EMAIL);
  });

  it("login replaces the session and persists it", async () => {
    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    act(() => result.current.login(EMPLOYEE, AccessContext.EMPLOYEE_PORTAL));

    expect(result.current.session?.user.email).toBe("stored@erpify.dev");
    expect(result.current.session?.context).toBe(AccessContext.EMPLOYEE_PORTAL);
    const persisted = JSON.parse(globalThis.localStorage.getItem(STORAGE_KEY) ?? "null");
    expect(persisted.user.email).toBe("stored@erpify.dev");
  });

  it("login defaults to the backoffice context", async () => {
    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    act(() => result.current.login(EMPLOYEE));

    expect(result.current.session?.context).toBe(AccessContext.BACKOFFICE);
  });

  it("logout clears the session and removes the stored payload", async () => {
    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    act(() => result.current.logout());

    expect(result.current.session).toBeNull();
    expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
    expect(globalThis.localStorage.getItem(STORAGE_KEY)).toBeNull();
  });

  it("override merges a permissions patch onto the live session", async () => {
    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    act(() => result.current.override({ permissions: [Permission.INVOICES_READ] }));

    expect(result.current.session?.permissions).toEqual([Permission.INVOICES_READ]);
    expect(result.current.session?.user.email).toBe(SEED_EMAIL);
  });

  it("override can downgrade the user to a non-active status (unauthenticated)", async () => {
    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    act(() => result.current.override({ user: { status: UserStatus.SUSPENDED } }));

    expect(result.current.session?.user.status).toBe(UserStatus.SUSPENDED);
    expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
  });

  it("override inherits roles and permissions from the base when the patch omits them", async () => {
    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    act(() => result.current.override({ context: AccessContext.SUPPLIER_PORTAL }));

    expect(result.current.session?.context).toBe(AccessContext.SUPPLIER_PORTAL);
    expect(result.current.session?.roles).toEqual([Role.ADMIN]);
    expect(result.current.session?.permissions).toContain(PERMISSION_WILDCARD);
  });
});
