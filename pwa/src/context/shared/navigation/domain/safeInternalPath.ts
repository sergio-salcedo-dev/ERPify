/**
 * Open-redirect guard for navigation targets that arrive from outside the app
 * (a `?next=` query param, location state, an API payload). Unlike
 * {@link safeHref} — which only blocks script-bearing schemes and would happily
 * return an absolute `https://evil.com` — this accepts a value only when it is a
 * root-relative in-app path, so handing it to `router.push` can never leave the
 * origin.
 *
 * Rejected (returns `fallback`): absolute URLs, protocol-relative `//host`,
 * backslash-smuggled `/\host` (browsers normalise `\` to `/`), and anything not
 * starting with a single `/`.
 *
 * @param value    Candidate path — typically untrusted.
 * @param fallback Returned when `value` is missing or not a safe in-app path.
 */
export function safeInternalPath(value: string | null | undefined, fallback: string): string {
  if (typeof value !== "string") return fallback;
  const trimmed = value.trim();
  // A safe target is a single leading slash NOT followed by another slash or a
  // backslash — `/path` is in-app, `//host` and `/\host` resolve to a foreign
  // origin once the browser parses them.
  if (!/^\/(?![/\\])/.test(trimmed)) return fallback;
  return trimmed;
}
