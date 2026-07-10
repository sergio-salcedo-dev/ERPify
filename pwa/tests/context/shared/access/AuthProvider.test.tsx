import { describe, it, expect, beforeEach, vi } from "vitest";
import { useContext } from "react";
import { renderHook, act, waitFor } from "@testing-library/react";

// Hydration is driven by the `/me` probe resolved through the DI container. Mock
// at that boundary so the provider never touches the network and each test
// controls what `/me` returns.
const me = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ me: (...args: unknown[]) => me(...args) }) },
}));

import {
  AuthProvider,
  AuthContext,
  AuthStatus,
  type AuthContextValue,
} from "@/context/shared/access/infrastructure/ui/AuthProvider";
import type { Identity } from "@/context/shared/access/domain/Identity";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";

function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("missing provider");
  return ctx;
}

function renderAuth() {
  return renderHook<AuthContextValue, void>(() => useAuth(), { wrapper: AuthProvider });
}

const ADMIN: Identity = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  email: "admin@erpify.dev",
  status: UserStatus.ACTIVE,
  roles: ["ADMIN"],
  permissions: [],
};

beforeEach(() => {
  me.mockReset();
});

describe("AuthProvider", () => {
  it("stays hydrating until the /me probe resolves, then authenticates", async () => {
    let settle!: (identity: Identity | null) => void;
    me.mockReturnValue(
      new Promise<Identity | null>((resolve) => {
        settle = resolve;
      }),
    );

    const { result } = renderAuth();

    expect(result.current.status).toBe(AuthStatus.HYDRATING);
    expect(result.current.session).toBeNull();

    await act(async () => {
      settle(ADMIN);
    });

    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));
  });

  it("builds the session from the /me identity (backend roles verbatim, no permissions)", async () => {
    me.mockResolvedValue(ADMIN);

    const { result } = renderAuth();

    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));
    expect(result.current.session?.user.email).toBe("admin@erpify.dev");
    expect(result.current.session?.user.status).toBe(UserStatus.ACTIVE);
    expect(result.current.session?.roles).toEqual(["ADMIN"]);
    expect(result.current.session?.permissions).toEqual([]);
    expect(result.current.session?.context).toBe(AccessContext.BACKOFFICE);
  });

  it("is unauthenticated when /me reports no live session (401 → null)", async () => {
    me.mockResolvedValue(null);

    const { result } = renderAuth();

    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED));
    expect(result.current.session).toBeNull();
  });

  it("is unauthenticated when the /me probe fails (no spinner-forever, no seed)", async () => {
    me.mockRejectedValue(new Error("network down"));

    const { result } = renderAuth();

    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED));
    expect(result.current.session).toBeNull();
  });

  it("login() re-hydrates from /me (never accepts a fabricated identity)", async () => {
    me.mockResolvedValueOnce(null).mockResolvedValueOnce(ADMIN);

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED));

    await act(async () => {
      await result.current.login();
    });

    expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
    expect(result.current.session?.user.email).toBe("admin@erpify.dev");
    expect(me).toHaveBeenCalledTimes(2);
  });

  it("logout() clears the session", async () => {
    me.mockResolvedValue(ADMIN);

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    act(() => result.current.logout());

    expect(result.current.session).toBeNull();
    expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
  });

  it("override() can downgrade the user to a non-active status (unauthenticated)", async () => {
    me.mockResolvedValue(ADMIN);

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    act(() => result.current.override({ user: { status: UserStatus.SUSPENDED } }));

    expect(result.current.session?.user.status).toBe(UserStatus.SUSPENDED);
    expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
  });

  it("override() inherits roles and context from the base when the patch omits them", async () => {
    me.mockResolvedValue(ADMIN);

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    act(() => result.current.override({ context: AccessContext.SUPPLIER_PORTAL }));

    expect(result.current.session?.context).toBe(AccessContext.SUPPLIER_PORTAL);
    expect(result.current.session?.roles).toEqual(["ADMIN"]);
  });
});
