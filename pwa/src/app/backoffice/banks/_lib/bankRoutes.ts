import { Routes } from "@/context/shared/domain/types/routes";

/**
 * Banks **UI navigation** routes — the paths the user navigates to via
 * `router.push` / `<Link href>`. This is deliberately separate from
 * `ApiEndpoints.ts`, which registers the Symfony **HTTP API** paths the PWA
 * `fetch`es: the two share the `backoffice/banks` substring but answer to
 * different sources of truth (the Next.js `app/` filesystem here vs. the
 * Symfony controller routes there) and rename for different reasons.
 *
 * Co-located with the banks module per the "entity-scoped paths live next to
 * the use case that builds them" rule (see
 * `src/context/shared/domain/types/routes.ts`). Mirrors the shape of
 * `bankPath(id)` in `ApiEndpoints.ts`: the dynamic segment is
 * `encodeURIComponent`-d here so call sites can't forget. Wrap the result in
 * `safeHref(...)` at the call site exactly as before.
 */
const BANKS_BASE = `${Routes.BACKOFFICE}/banks` as const;

export const bankRoutes = {
  /** Banks list page. */
  list: BANKS_BASE,
  /** Create-bank form. */
  new: `${BANKS_BASE}/new`,
  /** Bank detail page for a given id. */
  detail: (id: string): string => `${BANKS_BASE}/${encodeURIComponent(id)}`,
  /** Edit-bank form for a given id. */
  edit: (id: string): string => `${BANKS_BASE}/${encodeURIComponent(id)}/edit`,
} as const;
