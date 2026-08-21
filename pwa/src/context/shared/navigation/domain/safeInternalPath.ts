/**
 * Open-redirect guard for navigation targets that arrive from outside the app
 * (a `?next=` query param, location state, an API payload). Unlike
 * {@link safeHref} — which only blocks script-bearing schemes and would happily
 * return an absolute `https://evil.com` — this accepts a value only when it is a
 * root-relative in-app path, so handing it to `router.push` can never leave the
 * origin.
 *
 * Rejected (returns `fallback`): absolute URLs, protocol-relative `//host`,
 * backslash-smuggled `/\host` (browsers normalise `\` to `/`), whitespace-smuggled
 * `/<TAB>/host` (the URL parser strips TAB/LF/CR from ANYWHERE in a URL, while
 * `String.trim()` only strips them at the ends), and anything not starting with a
 * single `/`.
 *
 * @param value    Candidate path — typically untrusted.
 * @param fallback Returned when `value` is missing or not a safe in-app path.
 */
/** Origin no real deployment can hold, used only to resolve a candidate against. */
const SENTINEL_ORIGIN = "https://safe-internal-path.invalid";

export function safeInternalPath(value: string | null | undefined, fallback: string): string {
  if (typeof value !== "string") return fallback;
  const trimmed = value.trim();
  if (!trimmed.startsWith("/")) return fallback;
  // Ask the URL parser instead of describing it. A regex has to enumerate the shapes
  // that reach a foreign origin, and the enumeration was short by one whole class:
  // `String.trim()` strips TAB/LF/CR only at the ENDS, while the WHATWG parser strips
  // them ANYWHERE — so `/<TAB>/evil.com` satisfied "a single leading slash not followed
  // by a slash" and then resolved to `https://evil.com/`. Measured, all three of TAB,
  // LF and CR. Resolving against a sentinel is the same control the pagination
  // navigator already uses, and it is authoritative rather than descriptive: it answers
  // with the origin the consumer's own `new URL(...)` will compute.
  try {
    if (new URL(trimmed, SENTINEL_ORIGIN).origin !== SENTINEL_ORIGIN) return fallback;
  } catch {
    return fallback;
  }
  return trimmed;
}
