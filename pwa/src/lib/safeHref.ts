/**
 * Defensive sanitizer for `<a href>` / `<Link href>` values that are derived
 * from data the UI does not fully control (API payloads, URL params, user
 * input). React escapes JSX text by default, but it does NOT block the
 * `javascript:`, `data:` and `vbscript:` URL schemes — a `<a href={value}>`
 * with `value === "javascript:alert(1)"` will execute the script when the
 * link is clicked. {@link safeHref} rejects those schemes and returns
 * `fallback` instead.
 *
 * Use it for every dynamic href whose value can be influenced by an
 * untrusted source, even if the source is "just" the API. Defense in depth.
 *
 * @param href     Candidate URL — relative, absolute, or untrusted.
 * @param fallback Returned when `href` is missing or unsafe. Defaults to `#`.
 */
export function safeHref(href: string | null | undefined, fallback: string = "#"): string {
  if (typeof href !== "string") return fallback;
  const trimmed = href.trim();
  if (!trimmed) return fallback;
  // Reject the well-known XSS-active schemes regardless of casing or
  // whitespace obfuscation. Browsers strip whitespace from inside the
  // scheme prefix when parsing URLs (e.g. `java\tscript:` is parsed as
  // `javascript:`), so we collapse all whitespace before matching.
  const collapsed = trimmed.replace(/\s+/g, "").toLowerCase();
  if (
    collapsed.startsWith("javascript:") ||
    collapsed.startsWith("vbscript:") ||
    // `data:` URLs can carry text/html with inline scripts; we never use them
    // for navigation, so block the scheme entirely.
    collapsed.startsWith("data:") ||
    // `file:` likewise has no business in a web app.
    collapsed.startsWith("file:")
  ) {
    return fallback;
  }
  return trimmed;
}
