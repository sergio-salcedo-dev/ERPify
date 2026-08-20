type SessionExpiryListener = (expiring: boolean) => void;

// One fact, one owner. The transport DETECTS the expiry and the UI RENDERS it, and while
// each kept its own copy the two could disagree: the adapter's private latch was the only
// record that a bounce had started, so nothing above it could stop painting an error the
// user is being navigated away from. Module state rather than a React store because the
// detector is a module-level function inside an infrastructure adapter — there is no
// provider it can reach — and because the fact is per-document, which is exactly a
// module's lifetime.
let expiring = false;
const listeners = new Set<SessionExpiryListener>();

function publish(): void {
  listeners.forEach((listener) => listener(expiring));
}

/**
 * Claim the session-expiry bounce. Returns `false` when one is already in flight, which is
 * the single-flight guard: a burst of concurrent 401s (a dashboard firing several gated
 * requests at once) must navigate to /login exactly once, not race N redirects.
 */
export function beginSessionExpiry(): boolean {
  if (expiring) return false;
  expiring = true;
  publish();
  return true;
}

/**
 * The document stayed — the navigation was refused, or never committed. Releasing the claim
 * is what keeps a later 401 from being swallowed for the life of the document, and what
 * brings the UI back rather than leaving it blanked with no route away.
 */
export function endSessionExpiry(): void {
  if (!expiring) return;
  expiring = false;
  publish();
}

export function isSessionExpiring(): boolean {
  return expiring;
}

export function subscribeToSessionExpiry(listener: SessionExpiryListener): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}
