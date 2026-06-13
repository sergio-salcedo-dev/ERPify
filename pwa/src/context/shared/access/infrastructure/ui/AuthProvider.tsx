"use client";

import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import type { Session } from "../../domain/Session";
import type { Identity } from "../../domain/Identity";
import { UserStatus } from "../../domain/UserStatus";
import { Role } from "../../domain/Role";
import { PERMISSION_WILDCARD } from "../../domain/Permission";
import { AccessContext } from "../../domain/AccessContext";

const STORAGE_KEY = "erpify:session";

/** Seeded default: an ADMIN with the wildcard, so backoffice CRUD is usable out of the box. */
const SEED_SESSION: Session = {
  user: {
    id: "00000000-0000-7000-8000-000000000001",
    email: "admin@erpify.dev",
    status: UserStatus.ACTIVE,
    roles: [Role.ADMIN],
    permissions: [PERMISSION_WILDCARD],
  },
  roles: [Role.ADMIN],
  permissions: [PERMISSION_WILDCARD],
  context: AccessContext.BACKOFFICE,
};

export interface AuthContextValue {
  session: Session | null;
  /** Mocked login: replaces the session with the supplied identity (no validation). */
  login: (user: Identity, context?: AccessContext) => void;
  /** Clears the session (logout). */
  logout: () => void;
  /** Dev-only partial override (role/status/permissions), used by the switcher. */
  override: (patch: Partial<Session> & { user?: Partial<Identity> }) => void;
}

export const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: Readonly<{ children: ReactNode }>) {
  // Hydration-safe: SSR + first paint render the seed; a stored session applies
  // after mount (reading localStorage during render causes hydration mismatch).
  const [session, setSession] = useState<Session | null>(SEED_SESSION);

  useEffect(() => {
    try {
      const raw = globalThis.localStorage?.getItem(STORAGE_KEY);
      if (raw) setSession(JSON.parse(raw) as Session);
    } catch {
      // Corrupt/blocked storage → keep the seed.
    }
  }, []);

  const persist = useCallback((next: Session | null): void => {
    setSession(next);
    try {
      if (next) globalThis.localStorage?.setItem(STORAGE_KEY, JSON.stringify(next));
      else globalThis.localStorage?.removeItem(STORAGE_KEY);
    } catch {
      // best-effort convenience only
    }
  }, []);

  const login = useCallback(
    (user: Identity, context: AccessContext = AccessContext.BACKOFFICE): void => {
      persist({ user, roles: user.roles, permissions: user.permissions, context });
    },
    [persist],
  );

  const logout = useCallback((): void => persist(null), [persist]);

  const override = useCallback(
    (patch: Partial<Session> & { user?: Partial<Identity> }): void => {
      setSession((prev) => {
        const base = prev ?? SEED_SESSION;
        const user: Identity = { ...base.user, ...(patch.user ?? {}) };
        const next: Session = {
          ...base,
          ...patch,
          user,
          roles: patch.roles ?? user.roles,
          permissions: patch.permissions ?? user.permissions,
        };
        try {
          globalThis.localStorage?.setItem(STORAGE_KEY, JSON.stringify(next));
        } catch {
          /* ignore */
        }
        return next;
      });
    },
    [],
  );

  const value = useMemo<AuthContextValue>(
    () => ({ session, login, logout, override }),
    [session, login, logout, override],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
