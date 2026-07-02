import { Routes } from "@/context/shared/routing/domain/Routes";

/**
 * Standalone bank-accounts **UI navigation** routes — the paths the user
 * navigates to via `router.push` / `<Link href>` for the cross-bank hub. This
 * is deliberately separate from `ApiEndpoints.ts` (the Symfony HTTP paths the
 * PWA `fetch`es) and from the NESTED `bankAccountRoutes`
 * (`banks/{bankId}/accounts/...`), which still owns create/edit until the
 * standalone write flows land (iteration 2). Only the read surfaces (global
 * list + per-account detail) live here; row Edit defers to the nested route
 * using the row's `bankId`.
 *
 * The dynamic segment is `encodeURIComponent`-d here so call sites can't
 * forget; wrap the result in `safeHref(...)` at the call site.
 */
const BANK_ACCOUNTS_BASE = `${Routes.BACKOFFICE}/bank-accounts` as const;

export const bankAccountRoutes = {
  /** Global, cross-bank accounts list page. */
  list: BANK_ACCOUNTS_BASE,
  /** Per-account detail page for a given account id. */
  detail: (id: string): string => `${BANK_ACCOUNTS_BASE}/${encodeURIComponent(id)}`,
} as const;
