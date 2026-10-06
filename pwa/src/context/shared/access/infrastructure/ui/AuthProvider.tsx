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
import type { IdentityRepository } from "../../domain/IdentityRepository";
import type { SessionsRepository } from "../../domain/SessionsRepository";
import { UserStatus } from "../../domain/UserStatus";
import { IdentityServiceUnavailableError } from "../../domain/IdentityServiceUnavailableError";
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
 * session is `authenticated`; a server that answered 503 `service-unavailable`
 * (it could not reach a dependency it needs to decide, typically its session
 * store) is `unavailable`; no live session (401) is `unauthenticated`, and so is
 * a probe that got no answer. "No answer" is any failure other than those two —
 * offline, a timeout, a proxy's 5xx, a body that does not parse — because none of
 * them carries a decision from the server. There is no seeded default and no
 * auto-admin.
 *
 * `unavailable` is kept apart from `unauthenticated` because the guard sends the
 * two to different places: a back-office visitor who may well still be signed in
 * gains nothing from a sign-in form while the store is down — the login route
 * answers the same 503 — so the guard parks that case on the maintenance page,
 * which returns them once the server answers. The failures that stay
 * `unauthenticated` do so because neither proves the server is up and undecided,
 * and the sign-in form is where a transient blip recovers on its own.
 *
 * `unavailable` is the one status that clears itself: while in it the provider
 * re-probes `/me` with a bounded backoff, and at once when the tab becomes visible
 * or the browser comes back online, so the user leaves the maintenance page when
 * the server can answer again rather than at the next hard reload. Only an answer
 * is applied from a re-probe — a 200, a 401, or another 503 `service-unavailable`,
 * the last leaving it `unavailable` — and a re-probe that got none changes nothing,
 * so the status stays `unavailable`: leaving on one would send a visitor who is
 * still signed in to the sign-in form for want of a network, which is exactly the
 * moment a laptop waking from sleep produces. The cold probe and a sign-in's probe
 * keep the mapping above and read no answer as `unauthenticated`, because a sign-in
 * reports its failure through the form rather than waiting it out.
 */
export const AuthStatus = {
  HYDRATING: "hydrating",
  AUTHENTICATED: "authenticated",
  UNAUTHENTICATED: "unauthenticated",
  UNAVAILABLE: "unavailable",
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
   * It resolves to the session the provider holds once the probe settles, because
   * the caller cannot otherwise tell an authenticated provider from one the probe
   * never confirmed. That is the probe's own session when it was applied. When it
   * was not, something superseded it (a sign-out, a later probe), and the call waits
   * until that has written too — a second sign-in still in flight would otherwise
   * leave the caller holding the state from before either — then resolves to what
   * it wrote. So a caller never announces a sign-in the provider does not hold, and
   * never misses one it is about to. `null` conflates "no live
   * session" with "could not tell" (a 503 `service-unavailable` included), and
   * deliberately so: neither is grounds to announce a sign-in, so the caller's move
   * is the same for both.
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

/** What one `/me` probe established: the session, or why there is none. */
interface ProbeResult {
  session: Session | null;
  serviceUnavailable: boolean;
  /**
   * Whether the server decided anything: a 200, a 401 (resolved as no identity), or the 503
   * `service-unavailable` error. False for every other failure (network, timeout, a proxy's 5xx, a
   * malformed body), which carries no decision: the outage re-probe never applies it, while the cold
   * probe and a sign-in read it as "no live session" — see {@link AuthStatus}.
   */
  answered: boolean;
}

/** One probe's result, and whether it was the one allowed to write the state. */
interface ProbeOutcome {
  result: ProbeResult;
  applied: boolean;
}

/** Stands in for the latest probe once whatever advanced the generation has already written. */
const ALREADY_WRITTEN: Promise<unknown> = Promise.resolve();

/** First re-probe delay while `unavailable`; each further attempt doubles it. */
export const REPROBE_INITIAL_DELAY_MS = 5_000;
/** Ceiling of the re-probe backoff, so a long outage still recovers within a minute. */
export const REPROBE_MAX_DELAY_MS = 60_000;

function reprobeDelayMs(attempt: number): number {
  return Math.min(REPROBE_INITIAL_DELAY_MS * 2 ** attempt, REPROBE_MAX_DELAY_MS);
}

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
  const [serviceUnavailable, setServiceUnavailable] = useState(false);
  const [hydrated, setHydrated] = useState(false);
  // Several probes can be in flight at once (the cold probe, a sign-in re-probe, an outage
  // re-probe) and they may settle in any order. The invariant: a probe writes the state only if
  // nothing has advanced this generation since it started — so no result ever overwrites one from a
  // probe that started after it, and a slow cold probe settling after a fast sign-in cannot
  // overwrite the session that sign-in confirmed. The cold probe and a sign-in advance it when they
  // start; sign-out and unmount advance it too, so a probe they outlive is dropped. An outage
  // re-probe advances it only at the moment it writes, and writes only an answer: one that got no
  // answer supersedes nothing, so it can never cost a sign-in that did get one.
  const probeGeneration = useRef(0);
  // Settles once whatever last advanced the generation has written, or declined to: the probe that
  // advanced it, or `ALREADY_WRITTEN` for a sign-out, an unmount or an outage re-probe, which advance
  // it only as they write. `login()` waits on it when its own probe was superseded.
  const latestProbe = useRef<Promise<unknown>>(ALREADY_WRITTEN);
  // The session last written to the state, readable without waiting for a render: `login()`
  // answers with it when its own probe was superseded. Every write goes through `holdSession`.
  const heldSession = useRef<Session | null>(null);

  const holdSession = useCallback((next: Session | null): void => {
    heldSession.current = next;
    setSession(next);
  }, []);

  const resolveSession = useCallback(async (): Promise<ProbeResult> => {
    try {
      const identity = await identityRepository.me();
      return {
        session: identity ? sessionFromIdentity(identity) : null,
        serviceUnavailable: false,
        answered: true,
      };
    } catch (error) {
      // The adapter already maps 401 to null and a 503 `service-unavailable` to its own error;
      // any other failure (network, timeout, malformed body) carries no decision from the server.
      const serviceUnavailable = error instanceof IdentityServiceUnavailableError;
      return { session: null, serviceUnavailable, answered: serviceUnavailable };
    }
  }, [identityRepository]);

  const applyResult = useCallback(
    (result: ProbeResult): void => {
      holdSession(result.session);
      setServiceUnavailable(result.serviceUnavailable);
      setHydrated(true);
    },
    [holdSession],
  );

  /**
   * The cold probe and a sign-in's: supersede every probe in flight, then apply the result, answer
   * or not, unless a later probe, a sign-out or the unmount has superseded this one in turn.
   * Returns the very promise it records as the latest probe, so `login()` can tell whether anything
   * started after it.
   */
  const probe = useCallback((): Promise<ProbeOutcome> => {
    probeGeneration.current += 1;
    const generation = probeGeneration.current;
    const outcome = resolveSession().then((result): ProbeOutcome => {
      const applied = generation === probeGeneration.current;
      if (applied) applyResult(result);
      return { result, applied };
    });
    latestProbe.current = outcome;
    return outcome;
  }, [resolveSession, applyResult]);

  /**
   * The outage loop's: supersede nothing while in flight, and only at the end — when the server
   * answered and nothing advanced the generation meanwhile — supersede what is still in flight and
   * apply. Getting no answer, or being overtaken, leaves everything as it was.
   */
  const reprobeDuringOutage = useCallback(async (): Promise<ProbeOutcome> => {
    const observed = probeGeneration.current;
    const result = await resolveSession();
    const applied = result.answered && observed === probeGeneration.current;
    if (applied) {
      probeGeneration.current += 1;
      latestProbe.current = ALREADY_WRITTEN;
      applyResult(result);
    }
    return { result, applied };
  }, [resolveSession, applyResult]);

  useEffect(() => {
    void probe();
    return () => {
      probeGeneration.current += 1;
      latestProbe.current = ALREADY_WRITTEN;
    };
  }, [probe]);

  useEffect(() => {
    if (!serviceUnavailable) return;
    // Probes the loop has sent; the backoff grows with it, so a tick skipped while offline does not.
    let attempt = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let inFlight = false;
    let pendingReprobe = false;
    let stopped = false;

    // Leaving `unavailable` re-runs this effect and stops the loop; a result that is still
    // `unavailable` sets no new state, so the loop schedules its own next attempt. So does a probe
    // that got no answer, which is not applied at all, and a probe superseded by someone else's
    // (a sign-in re-probe): if that other probe also answered `unavailable`, nothing would re-run
    // this effect, and the loop would stall.
    function schedule(): void {
      clearTimeout(timer);
      timer = setTimeout(tick, reprobeDelayMs(attempt));
    }
    // A probe sent while the browser knows it is offline cannot be answered; the `online` listener
    // re-probes the moment that changes, and the next tick stays scheduled in case it never fires.
    function tick(): void {
      if (!globalThis.navigator.onLine) {
        schedule();
        return;
      }
      reprobe();
    }
    // A request arriving while a probe is in flight is held rather than dropped: a tab waking from
    // sleep sends one on `visibilitychange` before the network is back, and the `online` that
    // follows lands while it is still failing. When that probe settles without an answer, the held
    // request runs at once instead of waiting out the backoff; when it got one, it is already as
    // fresh as the held request could be, and the backoff stands.
    function reprobe(): void {
      if (stopped) return;
      if (inFlight) {
        pendingReprobe = true;
        return;
      }
      clearTimeout(timer);
      inFlight = true;
      attempt += 1;
      void reprobeDuringOutage().then(({ result, applied }) => {
        inFlight = false;
        const runHeldRequest = pendingReprobe && !result.answered;
        pendingReprobe = false;
        if (stopped || (applied && !result.serviceUnavailable)) return;
        if (runHeldRequest) {
          reprobe();
          return;
        }
        schedule();
      });
    }
    function reprobeWhenVisible(): void {
      if (globalThis.document.visibilityState === "visible") reprobe();
    }

    schedule();
    globalThis.document.addEventListener("visibilitychange", reprobeWhenVisible);
    globalThis.addEventListener("online", reprobe);
    return () => {
      stopped = true;
      clearTimeout(timer);
      globalThis.document.removeEventListener("visibilitychange", reprobeWhenVisible);
      globalThis.removeEventListener("online", reprobe);
    };
  }, [serviceUnavailable, reprobeDuringOutage]);

  // The held session rather than the probe's own: when a sign-out or a later probe superseded
  // this one, its result was never applied, and announcing it would contradict the provider. The
  // loop waits out every probe that superseded it in turn, so the answer is what the latest one
  // wrote rather than the state before it.
  const login = useCallback(async (): Promise<Session | null> => {
    let awaited: Promise<unknown> = probe();
    await awaited;
    while (awaited !== latestProbe.current) {
      awaited = latestProbe.current;
      await awaited;
    }
    return heldSession.current;
  }, [probe]);

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
        probeGeneration.current += 1;
        latestProbe.current = ALREADY_WRITTEN;
        holdSession(null);
        setServiceUnavailable(false);
        setHydrated(true);
      }
    },
    [sessionsRepository, holdSession],
  );

  const override = useCallback(
    (patch: Omit<Partial<Session>, "user"> & { user?: Partial<Identity> }): void => {
      const base = heldSession.current;
      if (!base) return;
      const user: Identity = { ...base.user, ...patch.user };
      holdSession({
        ...base,
        ...patch,
        user,
        roles: patch.roles ?? user.roles,
        permissions: patch.permissions ?? user.permissions,
      });
    },
    [holdSession],
  );

  const status = useMemo<AuthStatus>(() => {
    if (!hydrated) return AuthStatus.HYDRATING;
    if (serviceUnavailable) return AuthStatus.UNAVAILABLE;
    return session?.user.status === UserStatus.ACTIVE
      ? AuthStatus.AUTHENTICATED
      : AuthStatus.UNAUTHENTICATED;
  }, [hydrated, serviceUnavailable, session]);

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
