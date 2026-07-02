import { Routes } from "@/context/shared/routing/domain/Routes";

/**
 * Standalone bank-accounts **UI navigation** routes — the paths the user
 * navigates to via `router.push` / `<Link href>` for the cross-bank hub. This
 * is deliberately separate from `ApiEndpoints.ts` (the Symfony HTTP paths the
 * PWA `fetch`es) and from the NESTED `bankAccountRoutes`
 * (`banks/{bankId}/accounts/...`), which owns the per-bank surfaces (create +
 * the bank-scoped list). The standalone hub is self-contained for read and
 * edit: an account carries its own `bankId`, so editing needs no bank in the
 * URL. Create still lives under a bank (an account is born under one).
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
  /** Edit-account form for a given account id (bank-agnostic — the account owns its `bankId`). */
  edit: (id: string): string => `${BANK_ACCOUNTS_BASE}/${encodeURIComponent(id)}/edit`,
} as const;
