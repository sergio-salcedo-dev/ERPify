/**
 * Every URL prefix that belongs to the dev-tools surface — the hub itself
 * plus every fixture / gallery linked from it.
 *
 * Owned by the dev-tools domain so the production short-circuit
 * (`pwa/src/proxy.ts`) and the page-level guards stay in lock-step:
 * adding a new dev surface = a new entry here, then mirror the two
 * matcher entries (bare + nested) into `proxy.ts`'s `config.matcher`
 * — Next 16 / Turbopack require that array to be a static literal,
 * so the duplication can't be derived at runtime. The
 * `tests/proxy.test.ts` parity check fails the build if the two drift.
 */
export const DEV_TOOL_ROUTE_PREFIXES = ["/dev-tools", "/dev-error-gallery", "/dev-throw"] as const;

/**
 * True iff `pathname` belongs to the dev-tools surface and should be
 * short-circuited in production. Used by the middleware.
 */
export function isDevToolRoute(pathname: string): boolean {
  return DEV_TOOL_ROUTE_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
}
