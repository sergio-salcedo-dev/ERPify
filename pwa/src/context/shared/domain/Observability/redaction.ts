/**
 * Exact-key, case-insensitive denylist of sensitive field names, stripped from
 * any structured value before it leaves the client/server for an external sink
 * (Sentry today, Datadog later). Mirrors the API's `RedactionDenylist`
 * (`api/src/Shared/Application/Problem/RedactionDenylist.php`) so the front-end
 * scrub keeps parity with the RFC 9457 / Sentry `before_send` redaction on the
 * back-end. Strip semantics match the API: a denylisted key is REMOVED, not
 * replaced with a sentinel — the mere presence of a `password` key is a signal.
 *
 * Extending the list here should be mirrored in the API enum (and vice-versa).
 */
export const REDACTION_DENYLIST = [
  "password",
  "token",
  "secret",
  "authorization",
  "cookie",
  "ssn",
  "iban",
] as const;

const DENIED = new Set<string>(REDACTION_DENYLIST.map((key) => key.toLowerCase()));

/** Bounds recursion against pathological / cyclic structures. */
const MAX_DEPTH = 8;
/** Bounds the total work per scrub to prevent blocking the main thread. */
const MAX_NODES = 1000;

/** True when a key name is denylisted (exact match, case-insensitive ASCII). */
export function isDenylistedKey(key: string): boolean {
  return DENIED.has(key.toLowerCase());
}

/**
 * Recursively strips denylisted keys from a value at every depth — unlike the
 * API enum's single-level `filter`, because captured payloads nest (a request
 * body's `user.password`). Arrays are walked element-wise; primitives and
 * non-plain objects (Date, Map, Set, custom classes) pass through untouched.
 *
 * Depth-bounded (MAX_DEPTH) and node-bounded (MAX_NODES) so a cyclic or
 * massive object can never loop forever or block the main thread.
 */
export function scrubDeep(value: unknown, depth = 0, state = { nodes: 0 }): unknown {
  state.nodes += 1;

  if (value === null || typeof value !== "object") {
    return value;
  }

  // At the depth or node cap, return a sentinel rather than the raw object:
  // returning the value verbatim would let a denylisted key sitting past the
  // limit ride out unscrubbed, breaking the strip guarantee.
  if (depth >= MAX_DEPTH || state.nodes >= MAX_NODES) {
    return "[depth-limited]";
  }

  // Pass through common non-plain objects that shouldn't be recursed into.
  // scrubDeep only aims to redact keys from plain objects and arrays.
  if (value instanceof Date || value instanceof Map || value instanceof Set) {
    return value;
  }

  // If it's a non-plain object (has a custom constructor), pass it through
  // untouched to avoid accidentally breaking class instances or complex types.
  if (value.constructor !== Object && !Array.isArray(value)) {
    return value;
  }

  if (Array.isArray(value)) {
    return value.map((item) => scrubDeep(item, depth + 1, state));
  }

  const scrubbed: Record<string, unknown> = {};
  for (const [key, nested] of Object.entries(value as Record<string, unknown>)) {
    if (isDenylistedKey(key)) {
      continue;
    }
    scrubbed[key] = scrubDeep(nested, depth + 1, state);
  }
  return scrubbed;
}
