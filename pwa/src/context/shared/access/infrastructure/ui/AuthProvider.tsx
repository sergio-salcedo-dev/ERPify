"use client";

import {
  createContext,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
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

/**
 * Identity must be resolved before authorization is evaluated. Until the stored
 * session has been read, the provider is `hydrating` and guards render nothing —
 * no protected UI is shown on the strength of a default. Once resolved, an ACTIVE
 * session is `authenticated`; anything else (no session, INVITED, SUSPENDED,
 * DEACTIVATED) is `unauthenticated`.
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
  /** Mocked login: replaces the session with the supplied identity (no validation). */
  login: (user: Identity, context?: AccessContext) => void;
  /** Clears the session (logout). */
  logout: () => void;
  /** Dev-only partial override (role/status/permissions), used by the switcher. */
  override: (patch: Omit<Partial<Session>, "user"> & { user?: Partial<Identity> }) => void;
}

export const AuthContext = createContext<AuthContextValue | null>(null);

/**
 * Validate a deserialized session at the storage boundary. localStorage is
 * attacker/corruption-controlled: a structurally-invalid-but-parseable payload
 * (e.g. a missing `permissions` array) would otherwise crash `authorize()` on
 * the first guarded render. A failed check degrades to the seed, never throws.
 */
function parseStoredSession(raw: string): Session | null {
  let value: unknown;
  try {
    value = JSON.parse(raw);
  } catch {
    return null;
  }
  if (value === null || typeof value !== "object") return null;
  const candidate = value as Partial<Session>;
  const user = candidate.user;
  if (!user || typeof user !== "object") return null;
  const knownStatus = Object.values(UserStatus).includes(user.status);
  if (typeof user.id !== "string" || typeof user.email !== "string" || !knownStatus) return null;
  if (
    !Array.isArray(user.roles) ||
    !Array.isArray(user.permissions) ||
    !Array.isArray(candidate.roles) ||
    !Array.isArray(candidate.permissions) ||
    typeof candidate.context !== "string"
  ) {
    return null;
  }
  return candidate as Session;
}

export function AuthProvider({ children }: Readonly<{ children: ReactNode }>) {
  // Hydration gate: SSR and first client paint render with no session and
  // `hydrating` status, so no protected content can flash before the stored
  // session is read. The effect resolves it after mount.
  const [session, setSession] = useState<Session | null>(null);
  const [hydrated, setHydrated] = useState(false);

  // Mirror the live session so `override` can merge onto it without an impure
  // localStorage write inside a setState updater (which React may run twice).
  const sessionRef = useRef<Session | null>(session);
  useEffect(() => {
    sessionRef.current = session;
  }, [session]);

  const persist = useCallback((next: Session | null): void => {
    setSession(next);
    try {
      if (next) globalThis.localStorage?.setItem(STORAGE_KEY, JSON.stringify(next));
      else globalThis.localStorage?.removeItem(STORAGE_KEY);
    } catch {
      // best-effort convenience only
    }
  }, []);

  useEffect(() => {
    let resolved: Session | null = SEED_SESSION;
    try {
      const raw = globalThis.localStorage?.getItem(STORAGE_KEY);
      if (raw) resolved = parseStoredSession(raw) ?? SEED_SESSION;
    } catch {
      // Corrupt/blocked storage → fall back to the seed.
    }
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setSession(resolved);
    setHydrated(true);
  }, []);

  const login = useCallback(
    (user: Identity, context: AccessContext = AccessContext.BACKOFFICE): void => {
      persist({ user, roles: user.roles, permissions: user.permissions, context });
    },
    [persist],
  );

  const logout = useCallback((): void => persist(null), [persist]);

  const override = useCallback(
    (patch: Omit<Partial<Session>, "user"> & { user?: Partial<Identity> }): void => {
      const base = sessionRef.current ?? SEED_SESSION;
      const user: Identity = { ...base.user, ...patch.user };
      persist({
        ...base,
        ...patch,
        user,
        roles: patch.roles ?? user.roles,
        permissions: patch.permissions ?? user.permissions,
      });
    },
    [persist],
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
