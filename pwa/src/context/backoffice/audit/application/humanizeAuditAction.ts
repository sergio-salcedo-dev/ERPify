/**
 * Humanizes an audit `action` token into a readable English label. The raw token is forensic
 * evidence and is rendered verbatim **alongside** this label by the UI — this function only produces
 * the secondary, friendlier line and must never be treated as a replacement for the token (OQ-4).
 *
 * Two tiers (OQ-4): a curated dictionary for the live, business-meaningful actions, and a
 * deterministic fallback for everything else — notably the open-ended `ROUTE_*` family the access
 * log emits — so an unmapped or future token still reads cleanly without a code change. A curated
 * catalogue is never frozen over the open `ROUTE_*` space; the fallback covers the tail.
 */
const CURATED_LABELS: Readonly<Record<string, string>> = {
  BANK_CREATED: "Bank created",
  BANK_UPDATED: "Bank updated",
  BANK_DELETED: "Bank deleted",
  BANK_ACCOUNT_CREATED: "Bank account created",
  BANK_ACCOUNT_UPDATED: "Bank account updated",
  BANK_ACCOUNT_DELETED: "Bank account deleted",
  BANK_ACCOUNTS_VIEWED: "Bank accounts viewed",
  ACCESS_DENIED: "Access denied",
  GDPR_ERASURE_EXECUTED: "GDPR erasure executed",
};

const ROUTE_PREFIX = "ROUTE_";

export function humanizeAuditAction(action: string): string {
  const curated = CURATED_LABELS[action];
  if (curated) return curated;
  return titleCaseToken(
    action.startsWith(ROUTE_PREFIX) ? action.slice(ROUTE_PREFIX.length) : action,
  );
}

/** `BACKOFFICE_BANK_SEARCH` → `Backoffice Bank Search`; collapses underscores, title-cases words. */
function titleCaseToken(token: string): string {
  return token
    .split("_")
    .filter((word) => word.length > 0)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
    .join(" ");
}
