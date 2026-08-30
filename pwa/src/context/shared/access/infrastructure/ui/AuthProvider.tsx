"use client";

import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import type { Session } from "../../domain/Session";
import type { Identity } from "../../domain/Identity";
import type { IdentityRepository } from "../../domain/IdentityRepository";
import type { SessionsRepository } from "../../domain/SessionsRepository";
import { UserStatus } from "../../domain/UserStatus";
import { AccessContext } from "../../domain/AccessContext";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { telemetry } from "@/context/shared/observability/infrastructure";
import { apiScope } from "@/context/shared/observability/domain/TelemetryScope";
import { SessionExpiryCurtain } from "./SessionExpiryCurtain";

const IDENTITY_REPOSITORY_KEY = "IdentityRepository";
const SESSIONS_REPOSITORY_KEY = "SessionsRepository";

/**
 * Identity must be resolved before authorization is evaluated. Until the cold
 * `/me` probe resolves, the provider is `hydrating` and guards render nothing —
 * no protected UI is shown on the strength of a default. Once resolved, an ACTIVE
 * session is `authenticated`; no live session (401) or any failure is
 * `unauthenticated`. There is no seeded default and no auto-admin.
 */
export const AuthStatus = {
  HYDRATING: "hydrating",
  AUTHENTICATED: "authenticated",
  UNAUTHENTICATED: "unauthenticated",
} as const;
export type AuthStatus = (typeof AuthStatus)[keyof typeof AuthStatus];

export interface AuthContextValue {
  status: AuthStatus;
  session: Session | null;
  /**
   * Re-resolve the session from `/me` after a successful sign-in (the 204 has
   * already set the httpOnly session cookie). Never accepts a fabricated
   * identity — the server is the single source of truth.
   *
   * It resolves to the session the probe produced, because the caller cannot
   * otherwise tell an authenticated provider from one the probe never
   * confirmed. `null` conflates "no live session" with "could not tell", and
   * deliberately so: neither is grounds to announce a sign-in, so the caller's
   * move is the same for both.
   */
  login: () => Promise<Session | null>;
  /**
   * Sign out: revoke the current server-side session (so the server drops its
   * cookie) and clear the in-memory session. The server call is best-effort —
   * the local session is always cleared, so a failed revoke never leaves the
   * user stuck signed in.
   *
   * `budgetMs` caps how long that revoke may take. A caller that goes on to leave the page
   * needs the call to SETTLE, not merely to be best-effort: without a bound it can stay
   * pending for ever, and "never leaves the user stuck signed in" is only true of a promise
   * that resolves.
   */
  logout: (budgetMs?: number) => Promise<void>;
  /** Dev-only partial override (role/status/permissions), used by the switcher. */
  override: (patch: Omit<Partial<Session>, "user"> & { user?: Partial<Identity> }) => void;
}

export const AuthContext = createContext<AuthContextValue | null>(null);

/**
 * Build the session from a resolved identity. A 200 from the gated `/me`
 * endpoint means an admitted, ACTIVE session; the session holds exactly the
 * permissions the endpoint derived from the identity's roles — never more.
 */
function sessionFromIdentity(identity: Identity): Session {
  return {
    user: identity,
    roles: identity.roles,
    permissions: identity.permissions,
    context: AccessContext.BACKOFFICE,
  };
}

export function AuthProvider({ children }: Readonly<{ children: ReactNode }>) {
  const identityRepository = useMemo(
    () => container.get<IdentityRepository>(IDENTITY_REPOSITORY_KEY),
    [],
  );
  const sessionsRepository = useMemo(
    () => container.get<SessionsRepository>(SESSIONS_REPOSITORY_KEY),
    [],
  );

  // SSR and first client paint render with no session and `hydrating` status, so
  // no protected content can flash before `/me` resolves. `hydrated` flips once
  // the probe (or a login re-probe) has settled.
  const [session, setSession] = useState<Session | null>(null);
  const [hydrated, setHydrated] = useState(false);

  const resolveSession = useCallback(async (): Promise<Session | null> => {
    try {
      const identity = await identityRepository.me();
      return identity ? sessionFromIdentity(identity) : null;
    } catch {
      // The adapter already maps 401 to null; any other failure (network,
      // malformed body) is treated as "no live session" too.
      return null;
    }
  }, [identityRepository]);

  useEffect(() => {
    let active = true;
    resolveSession().then((resolved) => {
      if (!active) return;
      setSession(resolved);
      setHydrated(true);
    });
    return () => {
      active = false;
    };
  }, [resolveSession]);

  const login = useCallback(async (): Promise<Session | null> => {
    const resolved = await resolveSession();
    setSession(resolved);
    setHydrated(true);
    return resolved;
  }, [resolveSession]);

  const logout = useCallback(
    async (budgetMs?: number): Promise<void> => {
      try {
        await sessionsRepository.revokeCurrent(budgetMs);
      } catch (error) {
        // Best-effort: the gate 401s the stale session on its next use regardless,
        // so a failed server-side revoke must never trap the user signed in.
        telemetry.warn("Failed to revoke the session on sign-out", {
          scope: apiScope("sessions-revoke-current"),
          cause: error,
        });
      } finally {
        setSession(null);
        setHydrated(true);
      }
    },
    [sessionsRepository],
  );

  const override = useCallback(
    (patch: Omit<Partial<Session>, "user"> & { user?: Partial<Identity> }): void => {
      setSession((base) => {
        if (!base) return base;
        const user: Identity = { ...base.user, ...patch.user };
        return {
          ...base,
          ...patch,
          user,
          roles: patch.roles ?? user.roles,
          permissions: patch.permissions ?? user.permissions,
        };
      });
    },
    [],
  );

  const status = useMemo<AuthStatus>(() => {
    if (!hydrated) return AuthStatus.HYDRATING;
    return session?.user.status === UserStatus.ACTIVE
      ? AuthStatus.AUTHENTICATED
      : AuthStatus.UNAUTHENTICATED;
  }, [hydrated, session]);

  const value = useMemo<AuthContextValue>(
    () => ({ status, session, login, logout, override }),
    [status, session, login, logout, override],
  );

  return (
    <AuthContext.Provider value={value}>
      <SessionExpiryCurtain>{children}</SessionExpiryCurtain>
    </AuthContext.Provider>
  );
}
