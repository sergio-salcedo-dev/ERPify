const STORAGE_KEY = "erpify:credential-replaced";

/**
 * A request sent before the replacement cookie landed can still be answered 401 for as long as
 * the transport's own budget (30 s), so the marker has to outlive that.
 */
export const CREDENTIAL_REPLACEMENT_WINDOW_MS = 30_000;

// Per document: the instant of the last marker this tab already acted on. The marker is shared by
// every tab of the browser, so each tab has to be able to act on it once without consuming it
// for the others.
let actedOn: number | null = null;

/**
 * Called by the flows that replace the signed-in credential (change password, complete a reset),
 * which revoke every session and mint a new one onto the requesting tab. The cookie is shared, so
 * every other tab keeps working with the new one — except for requests that were already in flight
 * with the old one, whose 401 is not "the session ended".
 *
 * Only an instant is written: nothing about who, or why, and nothing a reader could use. Storage
 * that is unavailable (private mode, blocked) degrades to the previous behaviour.
 */
export function markCredentialReplaced(now: number = Date.now()): void {
  try {
    globalThis.localStorage.setItem(STORAGE_KEY, String(now));
  } catch {
    // No storage: the stale tab meets the login screen, as it did before this existed.
  }
}

/**
 * True once per marker per document, and only while the marker is recent. A tab acting on a stale
 * marker would turn an unrelated session expiry into a reload instead of the login screen, so
 * the window is the whole condition; acting once keeps a document that keeps getting 401 from
 * reloading in a loop.
 */
export function takeCredentialReplacement(now: number = Date.now()): boolean {
  let raw: string | null;
  try {
    raw = globalThis.localStorage.getItem(STORAGE_KEY);
  } catch {
    return false;
  }
  if (raw === null) return false;
  const markedAt = Number(raw);
  if (!Number.isFinite(markedAt)) return false;
  if (now - markedAt > CREDENTIAL_REPLACEMENT_WINDOW_MS) return false;
  if (actedOn === markedAt) return false;
  actedOn = markedAt;
  return true;
}

/** Test seam: a fresh document has acted on nothing. */
export function resetCredentialReplacementForTests(): void {
  actedOn = null;
}
