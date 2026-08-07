/**
 * Exact-key, case-insensitive denylist of sensitive field names, stripped from
 * any structured value before it leaves the client/server for an external sink
 * (Sentry today, Datadog later). Mirrors the API's `RedactionDenylist`
 * (`api/src/Shared/ErrorContract/Application/RedactionDenylist.php`) so the
 * front-end scrub keeps parity with the RFC 9457 / Sentry `before_send`
 * redaction on the back-end. Strip semantics match the API for STRUCTURED
 * values: a denylisted key is REMOVED, not replaced with a sentinel — the mere
 * presence of a `password` key is a signal.
 *
 * A query string is not a structured value and does not follow that rule: there
 * the key stays and the value becomes {@link REDACTION_SENTINEL}, because a
 * URL's diagnostic worth is its shape. Both sides agree on this too.
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
  "email",
  "phone_number",
  "address",
] as const;

/** True when a key name contains a denylisted substring (case-insensitive ASCII). */

const NORMALIZED_DENYLIST: string[] = REDACTION_DENYLIST.map((d: string) => d.toLowerCase());

/**
 * Whitespace and control bytes, which pad a parameter name without changing what it names. `\s` alone
 * is not enough: it does not cover NUL, and NUL is exactly the byte that walks `actorId%00` past a
 * whole match. Mirrors the API's `RequestUriRedaction::PADDING_BYTES`.
 */
const PADDING_BYTES = /[\s\u0000-\u001F\u007F]+/g;

/**
 * Bounds the decode loop below, so a key of stacked `%25`s cannot spin it. Mirrors the API's
 * `RequestUriRedaction::MAX_DECODE_PASSES`, because a spelling one side unwraps and the other does not is
 * an identifier that reaches one sink after being kept out of the other.
 */
const MAX_DECODE_PASSES = 4;

/**
 * A key reduced to what it actually names, so padding and encoding cannot walk it past an exact match.
 *
 * `URLSearchParams` decodes exactly once, which is one level short of the API: a caller who writes
 * `filters%255B0%255D%255Bvalue%255D` hands this a key still spelling `filters%5B0%5D%5Bvalue%5D`, and the
 * pattern matches neither. The sink records what the caller sent, not what our own UI meant to send.
 */
function reduceKey(key: string): string {
  let candidate: string = key.toLowerCase().replace(PADDING_BYTES, "");

  for (let pass = 0; pass < MAX_DECODE_PASSES; pass += 1) {
    let decoded: string;
    try {
      decoded = decodeURIComponent(candidate);
    } catch {
      // A malformed escape (`%`, `%zz`) is left as the literal bytes the caller sent, exactly as the API's
      // urldecode does — matching stays on what is already reduced rather than throwing at the sink.
      return candidate;
    }

    const reduced: string = decoded.toLowerCase().replace(PADDING_BYTES, "");

    if (reduced === candidate) {
      return candidate;
    }

    candidate = reduced;
  }

  return candidate;
}

/**
 * A value decoded until a URI's query surfaces, for the caller that follows a nested URI. Keys are read
 * through several decodings, and a value that stopped at one left the two halves of the same rule
 * disagreeing: `?next=%252Fa%253FactorId%253D<id>` carries no literal `?` after the single decoding
 * URLSearchParams performs, so the nested URI was never followed and the id travelled intact.
 *
 * The input is returned unchanged when no query ever surfaces, so a value that is not a URI keeps the
 * caller's bytes. Mirrors the API's `RequestUriRedaction::decodeUntilQuerySurfaces()`.
 */
export function decodeUntilQuerySurfaces(value: string): string {
  let candidate: string = value;

  for (let pass = 0; pass < MAX_DECODE_PASSES; pass += 1) {
    if (candidate.includes("?")) {
      return candidate;
    }

    let decoded: string;
    try {
      decoded = decodeURIComponent(candidate);
    } catch {
      return value;
    }

    if (decoded === candidate) {
      return value;
    }

    candidate = decoded;
  }

  return candidate.includes("?") ? candidate : value;
}

export function isDenylistedKey(key: string): boolean {
  const reduced: string = reduceKey(key);

  return NORMALIZED_DENYLIST.some((denied: string) => reduced.includes(denied));
}

/**
 * Identity axes carried in a URL rather than in a body. The audit screen keeps its filter state in the
 * query string, so the document URL names the people under investigation, and the API request it fires
 * repeats them under the positional search grammar. Mirrors `RequestUriRedaction::IDENTITY_KEYS` on the
 * API side; extending one list means extending the other.
 *
 * These stay OUT of {@link REDACTION_DENYLIST}: that list is matched as a substring against structured
 * keys as well, and `actorId`/`resourceId`/`correlationId` are response-payload property names, so
 * folding them in would silently start stripping fields the UI needs.
 *
 * `correlationId` holds no person id — it is a request-correlation UUID, redacted because the trail of
 * one reconstructs a session.
 */
export const IDENTITY_AXES = ["actorid", "resourceid", "correlationid"] as const;

/**
 * The same tuple widened for lookup, so an arbitrary key can be tested against it: `as const` makes
 * IDENTITY_AXES a tuple of literals, which admits no `string` argument. Widened `readonly` and not copied —
 * the axes are already lower-case, so there is nothing to normalise, and the immutability the `as const`
 * buys is worth keeping. Unlike the substring denylist above, an axis is matched WHOLE.
 */
const IDENTITY_AXIS_KEYS: readonly string[] = IDENTITY_AXES;

/**
 * The value axis of the positional search grammar. Index and array suffix are both permissive: the wire
 * spells the suffix `[]`, `[0]` or `[12]` depending on whether the caller used `http_build_query`, axios or
 * jQuery, and an event is a place where over-matching is the safe direction to be wrong in.
 */
const SEARCH_VALUE_KEY = /^filters\[-?\d*\]\[value\](\[-?\d*\])?$/;

/**
 * Replaces the value rather than dropping the pair, unlike the denylist's strip semantics: a URL's
 * diagnostic worth IS its shape, and a missing pair leaves a reader unable to tell a filtered request
 * from an unfiltered one. Same token the API and the access log write, so the sinks read alike.
 */
export const REDACTION_SENTINEL = "REDACTED";

/** True when a query-parameter name is an identity axis or the value axis of the search grammar. */
export function isIdentityAxisKey(key: string): boolean {
  const reduced: string = reduceKey(key);

  return IDENTITY_AXIS_KEYS.includes(reduced) || SEARCH_VALUE_KEY.test(reduced);
}

/** Bounds recursion against pathological / cyclic structures. */
const MAX_DEPTH = 8;
/** Bounds the total work per scrub to prevent blocking the main thread. */
const MAX_NODES = 1000;

/**
 * Recursively strips denylisted keys from a value at every depth — unlike the
 * API enum's single-level `filter`, because captured payloads nest (a request
 * body's `user.password`). Arrays are walked element-wise; primitives and
 * non-plain objects (Date, Map, Set, custom classes) pass through untouched.
 *
 * Depth-bounded (MAX_DEPTH) and node-bounded (MAX_NODES) so a cyclic or
 * massive object can never loop forever or block the main thread.
 */
export function scrubDeep(value: unknown, depth = 0, state?: { nodes: number }): unknown {
  const actualState = state ?? { nodes: 0 };
  actualState.nodes += 1;

  if (value === null || typeof value !== "object") {
    return value;
  }

  // At the depth or node cap, return a sentinel rather than the raw object:
  // returning the value verbatim would let a denylisted key sitting past the
  // limit ride out unscrubbed, breaking the strip guarantee.
  if (depth >= MAX_DEPTH || actualState.nodes >= MAX_NODES) {
    return "[depth-limited]";
  }

  // Pass through common non-plain objects that shouldn't be recursed into.
  // scrubDeep only aims to redact keys from plain objects and arrays.
  if (value instanceof Date || value instanceof Map || value instanceof Set) {
    return value;
  }

  // If it's a non-plain object (has a custom constructor other than Object),
  // pass it through untouched to avoid accidentally breaking class instances.
  // Objects with no prototype (constructor is undefined) are treated as plain.
  const ctor = (value as Record<string, unknown>).constructor;
  if (ctor && ctor !== Object && !Array.isArray(value)) {
    return value;
  }

  if (Array.isArray(value)) {
    return scrubArray(value, depth, actualState);
  }

  return scrubObject(value as Record<string, unknown>, depth, actualState);
}

function scrubArray(value: unknown[], depth: number, state: { nodes: number }): unknown[] {
  const scrubbedArray: unknown[] = [];
  for (const item of value) {
    if (state.nodes >= MAX_NODES) {
      scrubbedArray.push("[node-limited]");
      break;
    }
    scrubbedArray.push(scrubDeep(item, depth + 1, state));
  }
  return scrubbedArray;
}

function scrubObject(
  value: Record<string, unknown>,
  depth: number,
  state: { nodes: number },
): Record<string, unknown> {
  const scrubbed: Record<string, unknown> = {};
  for (const [key, nested] of Object.entries(value)) {
    if (state.nodes >= MAX_NODES) {
      scrubbed["[truncated]"] = "[node-limited]";
      break;
    }
    if (isDenylistedKey(key)) {
      continue;
    }
    scrubbed[key] = scrubDeep(nested, depth + 1, state);
  }
  return scrubbed;
}
