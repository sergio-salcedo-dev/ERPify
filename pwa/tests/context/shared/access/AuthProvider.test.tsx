import { describe, it, expect, beforeEach, vi } from "vitest";
import { useContext, useEffect, type ReactNode } from "react";
import { renderHook, render, act, waitFor } from "@testing-library/react";

// Hydration is driven by the `/me` probe resolved through the DI container. Mock
// at that boundary so the provider never touches the network and each test
// controls what `/me` returns.
const me = vi.fn();
const revokeCurrent = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: () => ({
      me: (...args: unknown[]) => me(...args),
      revokeCurrent: (...args: unknown[]) => revokeCurrent(...args),
    }),
  },
}));
vi.mock("@/context/shared/observability/infrastructure", () => ({
  telemetry: { warn: vi.fn(), error: vi.fn() },
}));
// The provider binds a 503 verdict to the route it was observed on, so the tests drive the route.
const nav = vi.hoisted(() => ({ pathname: "/backoffice" as string | null }));
vi.mock("next/navigation", () => ({ usePathname: () => nav.pathname }));

import {
  AuthProvider,
  AuthContext,
  AuthStatus,
  type AuthContextValue,
} from "@/context/shared/access/infrastructure/ui/AuthProvider";
import type { Identity } from "@/context/shared/access/domain/Identity";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { IdentityUnavailableError } from "@/context/shared/access/domain/IdentityUnavailableError";

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

function unavailable(): IdentityUnavailableError {
  return new IdentityUnavailableError();
}

function pending(): {
  promise: Promise<Identity | null>;
  settle: (identity: Identity | null) => void;
  fail: (error: unknown) => void;
} {
  let settle!: (identity: Identity | null) => void;
  let fail!: (error: unknown) => void;
  const promise = new Promise<Identity | null>((resolve, reject) => {
    settle = resolve;
    fail = reject;
  });
  return { promise, settle, fail };
}

beforeEach(() => {
  nav.pathname = "/backoffice";
  me.mockReset();
  revokeCurrent.mockReset();
  revokeCurrent.mockResolvedValue(undefined);
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

  it("is unavailable, with no session, when /me answers 503", async () => {
    me.mockRejectedValue(unavailable());

    const { result } = renderAuth();

    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAVAILABLE));
    expect(result.current.session).toBeNull();
  });

  it("login() resolves null and reads unavailable when its re-probe answers 503", async () => {
    me.mockResolvedValueOnce(null).mockRejectedValueOnce(unavailable());

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED));

    let resolved: unknown = "unset";
    await act(async () => {
      resolved = await result.current.login();
    });

    expect(resolved).toBeNull();
    expect(result.current.status).toBe(AuthStatus.UNAVAILABLE);
  });

  it("login() clears the unavailable verdict once /me answers 200", async () => {
    me.mockRejectedValueOnce(unavailable()).mockResolvedValueOnce(ADMIN);

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAVAILABLE));

    await act(async () => {
      await result.current.login();
    });

    expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
  });

  it("login() clears the unavailable verdict once /me answers 401", async () => {
    me.mockRejectedValueOnce(unavailable()).mockResolvedValueOnce(null);

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAVAILABLE));

    await act(async () => {
      await result.current.login();
    });

    expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
  });

  it("logout() clears the unavailable verdict", async () => {
    me.mockRejectedValue(unavailable());

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAVAILABLE));

    await act(async () => {
      await result.current.logout();
    });

    expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
  });

  it("treats a null pathname (outside the App Router) as one more route", async () => {
    nav.pathname = null;
    me.mockRejectedValue(unavailable());

    const { result } = renderAuth();

    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAVAILABLE));
  });

  describe("after a 503, a client navigation", () => {
    // Records the status from a CHILD's effect, which runs before the provider's own effects —
    // the same position RequireAuth reads it from. A status reset in a provider effect would
    // reach this recorder one commit late, after the guard had already redirected.
    function renderRecording(): { seen: string[]; rerender: () => void } {
      const seen: string[] = [];
      function Recorder() {
        const ctx = useContext(AuthContext);
        useEffect(() => {
          if (ctx) seen.push(ctx.status);
        });
        return null;
      }
      const tree = (): ReactNode => (
        <AuthProvider>
          <Recorder />
        </AuthProvider>
      );
      const view = render(tree());
      return { seen, rerender: () => view.rerender(tree()) };
    }

    it("reads hydrating before any effect runs, then authenticates on a 200 re-probe", async () => {
      me.mockRejectedValueOnce(unavailable());
      const { seen, rerender } = renderRecording();
      await waitFor(() => expect(seen.at(-1)).toBe(AuthStatus.UNAVAILABLE));

      let settle!: (identity: Identity | null) => void;
      me.mockReturnValueOnce(
        new Promise<Identity | null>((resolve) => {
          settle = resolve;
        }),
      );
      const before = seen.length;
      nav.pathname = "/backoffice/users";
      rerender();

      expect(seen[before]).toBe(AuthStatus.HYDRATING);
      expect(me).toHaveBeenCalledTimes(2);

      await act(async () => {
        settle(ADMIN);
      });
      await waitFor(() => expect(seen.at(-1)).toBe(AuthStatus.AUTHENTICATED));
      expect(seen.slice(before)).not.toContain(AuthStatus.UNAVAILABLE);
    });

    it("reads unavailable again when the re-probe answers 503 too", async () => {
      me.mockRejectedValue(unavailable());
      const { seen, rerender } = renderRecording();
      await waitFor(() => expect(seen.at(-1)).toBe(AuthStatus.UNAVAILABLE));

      const before = seen.length;
      nav.pathname = "/maintenance";
      rerender();

      expect(seen[before]).toBe(AuthStatus.HYDRATING);
      await waitFor(() => expect(seen.at(-1)).toBe(AuthStatus.UNAVAILABLE));
      expect(me).toHaveBeenCalledTimes(2);
    });

    it("stamps the verdict with the route current when the probe answered, not when it started", async () => {
      let fail!: (error: unknown) => void;
      me.mockReturnValueOnce(
        new Promise<Identity | null>((_resolve, reject) => {
          fail = reject;
        }),
      );
      const { seen, rerender } = renderRecording();

      nav.pathname = "/backoffice/users";
      rerender();

      await act(async () => {
        fail(unavailable());
      });

      await waitFor(() => expect(seen.at(-1)).toBe(AuthStatus.UNAVAILABLE));
      expect(me).toHaveBeenCalledTimes(1);
    });

    it("probes again, rather than replaying the verdict, on a return to its route before the re-probe answers", async () => {
      me.mockRejectedValueOnce(unavailable());
      const { seen, rerender } = renderRecording();
      await waitFor(() => expect(seen.at(-1)).toBe(AuthStatus.UNAVAILABLE));

      // The re-probe on /maintenance is slow; the visitor presses Try again before it answers.
      const slow = pending();
      me.mockReturnValueOnce(slow.promise);
      nav.pathname = "/maintenance";
      rerender();
      expect(me).toHaveBeenCalledTimes(2);

      const retried = pending();
      me.mockReturnValueOnce(retried.promise);
      const before = seen.length;
      nav.pathname = "/backoffice";
      rerender();

      expect(seen.slice(before)).not.toContain(AuthStatus.UNAVAILABLE);
      expect(seen.at(-1)).toBe(AuthStatus.HYDRATING);
      expect(me).toHaveBeenCalledTimes(3);

      await act(async () => {
        slow.fail(unavailable());
      });
      expect(seen.at(-1)).toBe(AuthStatus.HYDRATING);

      await act(async () => {
        retried.settle(ADMIN);
      });
      await waitFor(() => expect(seen.at(-1)).toBe(AuthStatus.AUTHENTICATED));
    });

    it("does not re-probe while the route stays the one the 503 was seen on", async () => {
      me.mockRejectedValue(unavailable());
      const { seen, rerender } = renderRecording();
      await waitFor(() => expect(seen.at(-1)).toBe(AuthStatus.UNAVAILABLE));

      rerender();

      expect(seen.at(-1)).toBe(AuthStatus.UNAVAILABLE);
      expect(me).toHaveBeenCalledTimes(1);
    });
  });

  describe("only the latest probe decides", () => {
    it("keeps a login() session when a slower cold probe answers 503 after it", async () => {
      const cold = pending();
      me.mockReturnValueOnce(cold.promise).mockResolvedValueOnce(ADMIN);

      const { result } = renderAuth();
      let resolved: unknown = "unset";
      await act(async () => {
        resolved = await result.current.login();
      });
      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);

      await act(async () => {
        cold.fail(unavailable());
      });

      expect(resolved).toMatchObject({ user: { email: "admin@erpify.dev" } });
      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
      expect(result.current.session?.user.email).toBe("admin@erpify.dev");
    });

    it("keeps a login() session when a slower cold probe answers 401 after it", async () => {
      const cold = pending();
      me.mockReturnValueOnce(cold.promise).mockResolvedValueOnce(ADMIN);

      const { result } = renderAuth();
      await act(async () => {
        await result.current.login();
      });

      await act(async () => {
        cold.settle(null);
      });

      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
    });

    it("keeps a login() session when a navigation re-probe answers 503 after it", async () => {
      me.mockRejectedValueOnce(unavailable());
      const { result, rerender } = renderAuth();
      await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAVAILABLE));

      const reprobe = pending();
      me.mockReturnValueOnce(reprobe.promise).mockResolvedValueOnce(ADMIN);
      nav.pathname = "/login";
      rerender();
      expect(me).toHaveBeenCalledTimes(2);

      await act(async () => {
        await result.current.login();
      });
      await act(async () => {
        reprobe.fail(unavailable());
      });

      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
    });

    it("keeps the signed-out state when a probe started before logout() answers 200 after it", async () => {
      const cold = pending();
      me.mockReturnValueOnce(cold.promise);

      const { result } = renderAuth();
      await act(async () => {
        await result.current.logout();
      });
      expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);

      await act(async () => {
        cold.settle(ADMIN);
      });

      expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
      expect(result.current.session).toBeNull();
    });

    it("login() resolves the later probe's null, and applies nothing of its own, when superseded", async () => {
      me.mockResolvedValueOnce(null);
      const { result } = renderAuth();
      await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED));

      const first = pending();
      me.mockReturnValueOnce(first.promise).mockResolvedValueOnce(null);
      let firstLogin!: Promise<unknown>;
      act(() => {
        firstLogin = result.current.login();
      });
      await act(async () => {
        await result.current.login();
      });
      await act(async () => {
        first.settle(ADMIN);
      });

      await expect(firstLogin).resolves.toBeNull();
      expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
    });

    it("login() resolves the session the later probe found when a probe superseded it", async () => {
      me.mockResolvedValueOnce(null);
      const { result } = renderAuth();
      await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED));

      const own = pending();
      const later = pending();
      me.mockReturnValueOnce(own.promise).mockReturnValueOnce(later.promise);
      let superseded!: Promise<unknown>;
      act(() => {
        superseded = result.current.login();
      });
      act(() => {
        void result.current.refresh();
      });

      let settledEarly = false;
      void superseded.then(() => {
        settledEarly = true;
      });
      await act(async () => {
        own.settle(null);
      });
      // Its own answer is discarded, so it waits for the winner rather than resolving null.
      expect(settledEarly).toBe(false);

      await act(async () => {
        later.settle(ADMIN);
      });

      await expect(superseded).resolves.toMatchObject({ user: { email: "admin@erpify.dev" } });
      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
    });

    it("login() resolves null when a logout() superseded it, even if its own probe found a session", async () => {
      me.mockResolvedValueOnce(null);
      const { result } = renderAuth();
      await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED));

      const own = pending();
      me.mockReturnValueOnce(own.promise);
      let superseded!: Promise<unknown>;
      act(() => {
        superseded = result.current.login();
      });
      await act(async () => {
        await result.current.logout();
      });
      await act(async () => {
        own.settle(ADMIN);
      });

      await expect(superseded).resolves.toBeNull();
      expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
    });

    it("refresh() re-probes on the route an unavailable verdict was seen on", async () => {
      me.mockRejectedValueOnce(unavailable()).mockResolvedValueOnce(null);
      const { result } = renderAuth();
      await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAVAILABLE));

      await act(async () => {
        await result.current.refresh();
      });

      expect(me).toHaveBeenCalledTimes(2);
      expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
    });
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

  it("logout() revokes the server session then clears the local one", async () => {
    me.mockResolvedValue(ADMIN);

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    await act(async () => {
      await result.current.logout();
    });

    expect(revokeCurrent).toHaveBeenCalledTimes(1);
    expect(result.current.session).toBeNull();
    expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
  });

  it("logout() hands the caller's budget to the session registry", async () => {
    me.mockResolvedValue(ADMIN);

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    await act(async () => {
      await result.current.logout(1_500);
    });

    // The middle hop of the sign-out budget, and the only one nothing was reading. Every other
    // link is pinned — layout→logout, repository→post, transport→abort — so dropping the
    // argument HERE let sign-out silently inherit the transport's 30 s default, a tenfold
    // regression of a user-facing bound with vitest, eslint, dependency-cruiser and tsc all green.
    expect(revokeCurrent).toHaveBeenCalledWith(1_500);
  });

  it("logout() asks for no budget when the caller names none", async () => {
    me.mockResolvedValue(ADMIN);

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    await act(async () => {
      await result.current.logout();
    });

    expect(revokeCurrent).toHaveBeenCalledWith(undefined);
  });

  it("logout() still clears the local session when the server revoke fails", async () => {
    me.mockResolvedValue(ADMIN);
    revokeCurrent.mockRejectedValueOnce(new Error("network down"));

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.AUTHENTICATED));

    await act(async () => {
      await result.current.logout();
    });

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
