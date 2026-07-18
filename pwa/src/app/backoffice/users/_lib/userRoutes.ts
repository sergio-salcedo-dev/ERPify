import { Routes } from "@/context/shared/routing/domain/Routes";

/**
 * Users **UI navigation** routes — the paths the user navigates to via
 * `router.push` / `<Link href>`. Co-located with the users module per the
 * "entity-scoped paths live next to the use case that builds them" rule. The
 * dynamic segment is `encodeURIComponent`-d here; wrap the result in
 * `safeHref(...)` at the call site.
 */
const USERS_BASE = `${Routes.BACKOFFICE}/users` as const;

export const userRoutes = {
  /** Users list page. */
  list: USERS_BASE,
  /** User detail page for a given id. */
  detail: (id: string): string => `${USERS_BASE}/${encodeURIComponent(id)}`,
  /** Invite-a-user form page. */
  invite: `${USERS_BASE}/invite`,
} as const;
