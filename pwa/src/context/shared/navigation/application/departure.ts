/**
 * Why this document is being left. The claim below is single-flight, so a reason is also the
 * answer to "who won" — which is what the sign-out race needed and a boolean could not give.
 */
export const DepartureReason = {
  /** The user asked to sign out. Ends on the public landing. */
  SIGN_OUT: "sign-out",
  /** A gated request came back 401. Ends on `/login?next=…&reason=session-expired`. */
  SESSION_EXPIRED: "session-expired",
} as const;

export type DepartureReason = (typeof DepartureReason)[keyof typeof DepartureReason];

type DepartureListener = (reason: DepartureReason | null) => void;

// One fact, one owner: a full-document navigation away from the authenticated app is in flight,
// and this is why. It lives in `navigation` rather than in `access` because the two producers —
// the sign-out menu and the transport's 401 handler — agree on nothing except that they are both
// about to call `hardNavigate`, which is this capability's. The transport already depends on
// `navigation` for the navigation itself and for `safeInternalPath`; publishing the claim here is
// the same conversation rather than a second capability's internals.
//
// Module state rather than a React store because one producer is a module-level function inside an
// infrastructure adapter — there is no provider it can reach — and because the fact is per-document,
// which is exactly a module's lifetime.
let departure: DepartureReason | null = null;
const listeners = new Set<DepartureListener>();

function publish(): void {
  listeners.forEach((listener) => listener(departure));
}

/**
 * Claim the departure for `reason`. Returns `false` when one is already in flight.
 *
 * That single flight is doing two jobs, and the second is why the reason exists. It is the guard
 * that makes a burst of concurrent 401s (a dashboard firing several gated requests at once)
 * navigate to `/login` exactly once instead of racing N redirects. It is ALSO what keeps a 401
 * arriving mid-sign-out from starting a second, contradictory navigation: the sign-out claims
 * before it revokes, so the expiry bounce cannot claim, and the user who asked to leave lands on
 * the public landing instead of on "session expired".
 *
 * A caller that did not win must not release: keep the return value and release only on `true`.
 */
export function claimDeparture(reason: DepartureReason): boolean {
  if (departure !== null) return false;
  departure = reason;
  publish();
  return true;
}

/**
 * The document stayed — the navigation was refused, or never committed. Releasing the claim is what
 * keeps a later 401 from being swallowed for the life of the document, and what brings the UI back
 * rather than leaving it blanked with no route away.
 */
export function releaseDeparture(): void {
  if (departure === null) return;
  departure = null;
  publish();
}

export function currentDeparture(): DepartureReason | null {
  return departure;
}

export function subscribeToDeparture(listener: DepartureListener): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}
