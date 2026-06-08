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

/** True when a key name is denylisted (exact match, case-insensitive ASCII). */
export function isDenylistedKey(key: string): boolean {
  return DENIED.has(key.toLowerCase());
}

/**
 * Recursively strips denylisted keys from a value at every depth — unlike the
 * API enum's single-level `filter`, because captured payloads nest (a request
 * body's `user.password`). Arrays are walked element-wise; primitives and
 * non-plain objects pass through untouched. Depth-bounded so a cyclic object
 * can never loop forever.
 */
export function scrubDeep(value: unknown, depth = 0): unknown {
  if (value === null || typeof value !== "object") {
    return value;
  }
  // At the depth cap return a sentinel rather than the raw object: returning the
  // value verbatim would let a denylisted key sitting past MAX_DEPTH ride out
  // unscrubbed, breaking the strip guarantee (and is also the cyclic-structure stop).
  if (depth >= MAX_DEPTH) {
    return "[depth-limited]";
  }

  if (Array.isArray(value)) {
    return value.map((item) => scrubDeep(item, depth + 1));
  }

  const scrubbed: Record<string, unknown> = {};
  for (const [key, nested] of Object.entries(value as Record<string, unknown>)) {
    if (isDenylistedKey(key)) {
      continue;
    }
    scrubbed[key] = scrubDeep(nested, depth + 1);
  }
  return scrubbed;
}
