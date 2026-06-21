import { Routes } from "@/context/shared/routing/domain/Routes";

/**
 * Top-level URL prefixes that belong to the dev-tools surface. The hub at
 * `/backoffice/dev-tools` (sourced from {@link Routes.DEV_TOOLS}) covers itself plus
 * every nested tool (`/backoffice/dev-tools/error-gallery`, future tools, …) via the
 * `:path*` matcher; `/dev-throw` is a sibling fixture used by the E2E
 * spec, not a tool entry, so it gets its own prefix.
 *
 * Owned by the dev-tools domain so the production short-circuit
 * (`pwa/src/proxy.ts`) and the page-level guards stay in lock-step:
 * adding a new top-level dev surface = a new entry here, then mirror
 * the two matcher entries (bare + nested) into `proxy.ts`'s
 * `config.matcher` — Next 16 / Turbopack require that array to be a
 * static literal, so the duplication can't be derived at runtime. The
 * `tests/proxy.test.ts` parity check fails the build if the two
 * drift.
 */
export const DEV_TOOL_ROUTE_PREFIXES = [Routes.DEV_TOOLS, "/dev-throw"] as const;

/**
 * True iff `pathname` belongs to the dev-tools surface and should be
 * short-circuited in production. Used by the proxy.
 */
export function isDevToolRoute(pathname: string): boolean {
  return DEV_TOOL_ROUTE_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
}
