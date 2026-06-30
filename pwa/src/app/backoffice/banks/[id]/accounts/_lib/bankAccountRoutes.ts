import { Routes } from "@/context/shared/routing/domain/Routes";

/**
 * Bank-account **UI navigation** routes — the paths the user navigates to via
 * `router.push` / `<Link href>`. Deliberately separate from `ApiEndpoints.ts`,
 * which registers the Symfony **HTTP API** paths the PWA `fetch`es: the UI nests
 * the account surfaces under the owning bank (`/banks/{bankId}/accounts/...`) for
 * context, while the write API is the standalone `/bank-accounts/{id}`.
 *
 * Both dynamic segments are `encodeURIComponent`-d here so call sites can't
 * forget; wrap the result in `safeHref(...)` at the call site.
 */
const banksBase = `${Routes.BACKOFFICE}/banks` as const;

function accountsBase(bankId: string): string {
  return `${banksBase}/${encodeURIComponent(bankId)}/accounts`;
}

export const bankAccountRoutes = {
  /** Accounts list (the hub) for a given bank. */
  list: (bankId: string): string => accountsBase(bankId),
  /** Create-account form for a given bank. */
  new: (bankId: string): string => `${accountsBase(bankId)}/new`,
  /** Edit-account form for a given account under a given bank. */
  edit: (bankId: string, accountId: string): string =>
    `${accountsBase(bankId)}/${encodeURIComponent(accountId)}/edit`,
} as const;
