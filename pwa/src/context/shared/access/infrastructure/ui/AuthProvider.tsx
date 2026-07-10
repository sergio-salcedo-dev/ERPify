"use client";

import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import type { Session } from "../../domain/Session";
import type { Identity } from "../../domain/Identity";
import type { IdentityRepository } from "../../domain/IdentityRepository";
import { UserStatus } from "../../domain/UserStatus";
import { AccessContext } from "../../domain/AccessContext";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";

const IDENTITY_REPOSITORY_KEY = "IdentityRepository";

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
   */
  login: () => Promise<void>;
  /** Clear the in-memory session (logout). */
  logout: () => void;
  /** Dev-only partial override (role/status/permissions), used by the switcher. */
  override: (patch: Omit<Partial<Session>, "user"> & { user?: Partial<Identity> }) => void;
}

export const AuthContext = createContext<AuthContextValue | null>(null);

/**
 * Build the session from a resolved identity. A 200 from the gated `/me`
 * endpoint means an admitted, ACTIVE session; `/me` carries no permission set,
 * so the session holds exactly the identity's (currently empty) permissions.
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
    void resolveSession().then((resolved) => {
      if (!active) return;
      setSession(resolved);
      setHydrated(true);
    });
    return () => {
      active = false;
    };
  }, [resolveSession]);

  const login = useCallback(async (): Promise<void> => {
    const resolved = await resolveSession();
    setSession(resolved);
    setHydrated(true);
  }, [resolveSession]);

  const logout = useCallback((): void => {
    setSession(null);
    setHydrated(true);
  }, []);

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

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
