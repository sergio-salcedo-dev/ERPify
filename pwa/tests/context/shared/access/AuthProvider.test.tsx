import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { useContext } from "react";
import { renderHook, act, waitFor } from "@testing-library/react";

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

import {
  AuthProvider,
  AuthContext,
  AuthStatus,
  REPROBE_INITIAL_DELAY_MS,
  REPROBE_MAX_DELAY_MS,
  type AuthContextValue,
} from "@/context/shared/access/infrastructure/ui/AuthProvider";
import type { Identity } from "@/context/shared/access/domain/Identity";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { IdentityServiceUnavailableError } from "@/context/shared/access/domain/IdentityServiceUnavailableError";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import {
  MALFORMED_RESPONSE_ENVELOPE,
  NETWORK_ERROR,
  REQUEST_TIMEOUT,
} from "@/context/shared/http-client/domain/HttpClient";
import type { Session } from "@/context/shared/access/domain/Session";

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
  revokeCurrent.mockReset();
  revokeCurrent.mockResolvedValue(undefined);
});

afterEach(() => {
  vi.useRealTimers();
});

interface Deferred<T> {
  promise: Promise<T>;
  resolve: (value: T) => void;
  reject: (reason: unknown) => void;
}

function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

/** Advance the fake clock and let every probe the advance started settle into state. */
async function advance(ms: number): Promise<void> {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms);
  });
}

function setVisibility(state: DocumentVisibilityState): void {
  Object.defineProperty(document, "visibilityState", { configurable: true, get: () => state });
}

function setOnline(online: boolean): void {
  Object.defineProperty(navigator, "onLine", { configurable: true, get: () => online });
}

function problem(type: string, status: number): HttpError {
  return new HttpError({
    type,
    title: "The request failed",
    status,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
  });
}

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

  it("is unauthenticated when the /me body is malformed (not a store outage)", async () => {
    me.mockRejectedValue(
      new HttpError({
        type: MALFORMED_RESPONSE_ENVELOPE,
        title: "API response did not match the expected shape",
        status: HttpStatus.OK,
        instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
        "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
      }),
    );

    const { result } = renderAuth();

    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED));
    expect(result.current.session).toBeNull();
  });

  it("is unavailable, not unauthenticated, when /me answers service-unavailable", async () => {
    me.mockRejectedValue(new IdentityServiceUnavailableError());

    const { result } = renderAuth();

    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAVAILABLE));
    expect(result.current.session).toBeNull();
  });

  it("login() leaves the unavailable state once the server answers again", async () => {
    me.mockRejectedValueOnce(new IdentityServiceUnavailableError()).mockResolvedValueOnce(ADMIN);

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAVAILABLE));

    let resolved: unknown;
    await act(async () => {
      resolved = await result.current.login();
    });

    expect(resolved).not.toBeNull();
    expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
  });

  it("login() resolves null and reports unavailable while the server cannot answer", async () => {
    me.mockResolvedValueOnce(null).mockRejectedValueOnce(new IdentityServiceUnavailableError());

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED));

    let resolved: unknown = "unset";
    await act(async () => {
      resolved = await result.current.login();
    });

    expect(resolved).toBeNull();
    expect(result.current.status).toBe(AuthStatus.UNAVAILABLE);
  });

  it("logout() clears an unavailable state to unauthenticated", async () => {
    me.mockRejectedValue(new IdentityServiceUnavailableError());

    const { result } = renderAuth();
    await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAVAILABLE));

    await act(async () => {
      await result.current.logout();
    });

    expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
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

  describe("while unavailable", () => {
    beforeEach(() => {
      vi.useFakeTimers();
      setVisibility("visible");
    });

    afterEach(() => {
      // Drop the own-property overrides so the prototypes' getters are visible again.
      Reflect.deleteProperty(document, "visibilityState");
      Reflect.deleteProperty(navigator, "onLine");
    });

    it("re-probes /me after the first delay and authenticates once the server answers", async () => {
      me.mockRejectedValueOnce(new IdentityServiceUnavailableError()).mockResolvedValueOnce(ADMIN);

      const { result } = renderAuth();
      await advance(0);
      expect(result.current.status).toBe(AuthStatus.UNAVAILABLE);
      expect(me).toHaveBeenCalledTimes(1);

      await advance(REPROBE_INITIAL_DELAY_MS - 1);
      expect(me).toHaveBeenCalledTimes(1);

      await advance(1);
      expect(me).toHaveBeenCalledTimes(2);
      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
      expect(result.current.session?.user.email).toBe("admin@erpify.dev");

      // Leaving `unavailable` stops the loop: nothing probes again on its own.
      await advance(10 * REPROBE_MAX_DELAY_MS);
      expect(me).toHaveBeenCalledTimes(2);
      expect(vi.getTimerCount()).toBe(0);
    });

    it("lands on unauthenticated when the recovered server reports no live session", async () => {
      me.mockRejectedValueOnce(new IdentityServiceUnavailableError()).mockResolvedValueOnce(null);

      const { result } = renderAuth();
      await advance(0);
      await advance(REPROBE_INITIAL_DELAY_MS);

      expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
      expect(vi.getTimerCount()).toBe(0);
    });

    it("backs off exponentially up to the cap while the server keeps answering 503", async () => {
      me.mockRejectedValue(new IdentityServiceUnavailableError());

      const { result } = renderAuth();
      await advance(0);

      const expectedDelays = [5_000, 10_000, 20_000, 40_000, 60_000, 60_000, 60_000];
      expect(expectedDelays[0]).toBe(REPROBE_INITIAL_DELAY_MS);
      expect(Math.max(...expectedDelays)).toBe(REPROBE_MAX_DELAY_MS);
      let calls = 1;
      for (const delay of expectedDelays) {
        await advance(delay - 1);
        expect(me).toHaveBeenCalledTimes(calls);
        await advance(1);
        calls += 1;
        expect(me).toHaveBeenCalledTimes(calls);
      }
      expect(result.current.status).toBe(AuthStatus.UNAVAILABLE);
      // One pending timer at a time, never a pile-up.
      expect(vi.getTimerCount()).toBe(1);
    });

    it("clears its timer and listeners on unmount", async () => {
      me.mockRejectedValue(new IdentityServiceUnavailableError());

      const { unmount } = renderAuth();
      await advance(0);
      expect(vi.getTimerCount()).toBe(1);

      unmount();

      expect(vi.getTimerCount()).toBe(0);
      await act(async () => {
        globalThis.dispatchEvent(new Event("online"));
        document.dispatchEvent(new Event("visibilitychange"));
      });
      await advance(10 * REPROBE_MAX_DELAY_MS);
      expect(me).toHaveBeenCalledTimes(1);
    });

    it("clears its timer when sign-out leaves the unavailable state", async () => {
      me.mockRejectedValue(new IdentityServiceUnavailableError());

      const { result } = renderAuth();
      await advance(0);

      await act(async () => {
        await result.current.logout();
      });

      expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
      expect(vi.getTimerCount()).toBe(0);
      await advance(10 * REPROBE_MAX_DELAY_MS);
      expect(me).toHaveBeenCalledTimes(1);
    });

    it("re-probes at once when the tab becomes visible, and not while it is hidden", async () => {
      me.mockRejectedValueOnce(new IdentityServiceUnavailableError()).mockResolvedValueOnce(ADMIN);

      const { result } = renderAuth();
      await advance(0);

      setVisibility("hidden");
      await act(async () => {
        document.dispatchEvent(new Event("visibilitychange"));
      });
      expect(me).toHaveBeenCalledTimes(1);

      setVisibility("visible");
      await act(async () => {
        document.dispatchEvent(new Event("visibilitychange"));
      });
      await advance(0);

      expect(me).toHaveBeenCalledTimes(2);
      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
    });

    it("re-probes at once when the browser comes back online", async () => {
      me.mockRejectedValueOnce(new IdentityServiceUnavailableError()).mockResolvedValueOnce(ADMIN);

      const { result } = renderAuth();
      await advance(0);

      await act(async () => {
        globalThis.dispatchEvent(new Event("online"));
      });
      await advance(0);

      expect(me).toHaveBeenCalledTimes(2);
      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
    });

    // A re-probe that got no answer proves nothing about the session, so it must not be read as
    // "signed out": that would send a visitor who is still signed in to the sign-in form.
    it.each([
      ["a network failure", new TypeError("Failed to fetch")],
      ["a transport error with no response", problem(NETWORK_ERROR, 0)],
      ["a client timeout", problem(REQUEST_TIMEOUT, 0)],
      ["a proxy's 502", problem("about:blank", 502)],
      ["a malformed body", problem(MALFORMED_RESPONSE_ENVELOPE, HttpStatus.OK)],
    ])(
      "stays unavailable and keeps re-probing when a re-probe gets %s",
      async (_label, failure) => {
        me.mockRejectedValueOnce(new IdentityServiceUnavailableError())
          .mockRejectedValueOnce(failure)
          .mockResolvedValueOnce(ADMIN);

        const { result } = renderAuth();
        await advance(0);
        await advance(REPROBE_INITIAL_DELAY_MS);

        expect(me).toHaveBeenCalledTimes(2);
        expect(result.current.status).toBe(AuthStatus.UNAVAILABLE);
        expect(vi.getTimerCount()).toBe(1);

        await advance(2 * REPROBE_INITIAL_DELAY_MS);
        expect(me).toHaveBeenCalledTimes(3);
        expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
      },
    );

    it("stays unavailable when the tab wakes before the network does", async () => {
      me.mockRejectedValueOnce(new IdentityServiceUnavailableError())
        .mockRejectedValueOnce(new TypeError("Failed to fetch"))
        .mockResolvedValueOnce(ADMIN);

      const { result } = renderAuth();
      await advance(0);

      await act(async () => {
        document.dispatchEvent(new Event("visibilitychange"));
      });
      await advance(0);
      expect(me).toHaveBeenCalledTimes(2);
      expect(result.current.status).toBe(AuthStatus.UNAVAILABLE);

      await act(async () => {
        globalThis.dispatchEvent(new Event("online"));
      });
      await advance(0);
      expect(me).toHaveBeenCalledTimes(3);
      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
    });

    it("skips a tick while the browser is offline and re-probes when it comes back", async () => {
      me.mockRejectedValueOnce(new IdentityServiceUnavailableError()).mockResolvedValueOnce(ADMIN);

      const { result } = renderAuth();
      await advance(0);

      setOnline(false);
      await advance(REPROBE_INITIAL_DELAY_MS);
      expect(me).toHaveBeenCalledTimes(1);
      expect(result.current.status).toBe(AuthStatus.UNAVAILABLE);
      // The skipped tick leaves the next one scheduled, in case `online` never fires.
      expect(vi.getTimerCount()).toBe(1);

      setOnline(true);
      await act(async () => {
        globalThis.dispatchEvent(new Event("online"));
      });
      await advance(0);
      expect(me).toHaveBeenCalledTimes(2);
      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
    });

    // A sign-in keeps the cold-probe mapping: it is not the loop, and a failure there is reported
    // by the form rather than waited out.
    it("lets a sign-in that gets no answer leave the unavailable state", async () => {
      me.mockRejectedValueOnce(new IdentityServiceUnavailableError()).mockRejectedValueOnce(
        new TypeError("Failed to fetch"),
      );

      const { result } = renderAuth();
      await advance(0);

      let resolved: unknown = "unset";
      await act(async () => {
        resolved = await result.current.login();
      });

      expect(resolved).toBeNull();
      expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
      expect(vi.getTimerCount()).toBe(0);
    });

    it("resolves login() to null when an outage re-probe answering 503 supersedes it", async () => {
      const signInProbe = deferred<Identity | null>();
      me.mockRejectedValueOnce(new IdentityServiceUnavailableError())
        .mockReturnValueOnce(signInProbe.promise)
        .mockRejectedValueOnce(new IdentityServiceUnavailableError());

      const { result } = renderAuth();
      await advance(0);

      let pending!: Promise<Session | null>;
      act(() => {
        pending = result.current.login();
      });
      await advance(REPROBE_INITIAL_DELAY_MS);
      expect(me).toHaveBeenCalledTimes(3);

      let resolved: unknown = "unset";
      await act(async () => {
        signInProbe.resolve(ADMIN);
        resolved = await pending;
      });

      // The sign-in's own answer was superseded, so the caller hears what the provider holds.
      expect(resolved).toBeNull();
      expect(result.current.status).toBe(AuthStatus.UNAVAILABLE);
    });

    it("does not stack a second probe on one still in flight", async () => {
      const slow = deferred<Identity | null>();
      me.mockRejectedValueOnce(new IdentityServiceUnavailableError()).mockReturnValueOnce(
        slow.promise,
      );

      const { result } = renderAuth();
      await advance(0);
      await advance(REPROBE_INITIAL_DELAY_MS);
      expect(me).toHaveBeenCalledTimes(2);

      await act(async () => {
        globalThis.dispatchEvent(new Event("online"));
        document.dispatchEvent(new Event("visibilitychange"));
      });
      expect(me).toHaveBeenCalledTimes(2);

      await act(async () => {
        slow.resolve(ADMIN);
      });
      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
    });

    // A sign-in re-probe started after the loop's own supersedes it. If that sign-in probe ALSO
    // answers 503, the state does not change, so nothing re-runs the loop's effect: the superseded
    // loop probe has to schedule the next attempt itself, or the provider stays unavailable for ever.
    it("keeps re-probing when its own probe was superseded by a sign-in that also got a 503", async () => {
      const loopProbe = deferred<Identity | null>();
      const signInProbe = deferred<Identity | null>();
      me.mockRejectedValueOnce(new IdentityServiceUnavailableError())
        .mockReturnValueOnce(loopProbe.promise)
        .mockReturnValueOnce(signInProbe.promise)
        .mockResolvedValueOnce(ADMIN);

      const { result } = renderAuth();
      await advance(0);
      await advance(REPROBE_INITIAL_DELAY_MS);
      expect(me).toHaveBeenCalledTimes(2);

      let signIn!: Promise<unknown>;
      act(() => {
        signIn = result.current.login();
      });
      await act(async () => {
        loopProbe.resolve(ADMIN);
        signInProbe.reject(new IdentityServiceUnavailableError());
        await signIn;
      });
      // The superseded loop probe answered ADMIN, but only the latest probe may write the state.
      expect(result.current.status).toBe(AuthStatus.UNAVAILABLE);
      expect(me).toHaveBeenCalledTimes(3);

      await advance(2 * REPROBE_INITIAL_DELAY_MS);
      expect(me).toHaveBeenCalledTimes(4);
      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
    });
  });

  describe("probes settling out of order", () => {
    it("keeps a sign-in's session when the slower cold probe settles after it as unavailable", async () => {
      const cold = deferred<Identity | null>();
      me.mockReturnValueOnce(cold.promise).mockResolvedValueOnce(ADMIN);

      const { result } = renderAuth();
      await act(async () => {
        await result.current.login();
      });
      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);

      await act(async () => {
        cold.reject(new IdentityServiceUnavailableError());
      });

      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
      expect(result.current.session?.user.email).toBe("admin@erpify.dev");
    });

    it("keeps a sign-in's session when the slower cold probe settles after it with no session", async () => {
      const cold = deferred<Identity | null>();
      me.mockReturnValueOnce(cold.promise).mockResolvedValueOnce(ADMIN);

      const { result } = renderAuth();
      await act(async () => {
        await result.current.login();
      });

      await act(async () => {
        cold.resolve(null);
      });

      expect(result.current.status).toBe(AuthStatus.AUTHENTICATED);
    });

    it("applies only the later sign-in probe when the superseded cold probe settles first", async () => {
      const cold = deferred<Identity | null>();
      const signIn = deferred<Identity | null>();
      me.mockReturnValueOnce(cold.promise).mockReturnValueOnce(signIn.promise);

      const { result } = renderAuth();
      let resolved: unknown = "unset";
      let pending!: Promise<unknown>;
      act(() => {
        pending = result.current.login();
      });

      await act(async () => {
        cold.resolve(ADMIN);
      });
      // The cold probe was superseded by the sign-in one, so its answer is not applied.
      expect(result.current.status).toBe(AuthStatus.HYDRATING);

      await act(async () => {
        signIn.resolve(null);
        resolved = await pending;
      });

      expect(resolved).toBeNull();
      expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
    });

    it("drops a probe that sign-out outlives", async () => {
      const signIn = deferred<Identity | null>();
      me.mockResolvedValueOnce(null).mockReturnValueOnce(signIn.promise);

      const { result } = renderAuth();
      await waitFor(() => expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED));

      let pending!: Promise<unknown>;
      act(() => {
        pending = result.current.login();
      });
      await act(async () => {
        await result.current.logout();
      });
      let resolved: unknown = "unset";
      await act(async () => {
        signIn.resolve(ADMIN);
        resolved = await pending;
      });

      // The caller must not announce a sign-in the provider dropped.
      expect(resolved).toBeNull();
      expect(result.current.status).toBe(AuthStatus.UNAUTHENTICATED);
      expect(result.current.session).toBeNull();
    });
  });
});
