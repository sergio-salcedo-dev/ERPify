// Page-size options for the audit timeline, all ≤ WIRE_MAX_LIMIT (100): the API caps `limit` at 100
// (422 otherwise). `auditPaginate.test.ts` pins the ≤-cap invariant against the single source of
// truth (`WIRE_MAX_LIMIT`), so a future option can never exceed it.
export const AUDIT_PAGE_SIZE_OPTIONS = [25, 50, 100] as const;
export type AuditPageSize = (typeof AUDIT_PAGE_SIZE_OPTIONS)[number];
export const AUDIT_PAGE_SIZE_DEFAULT: AuditPageSize = 25;

/** Membership guard: narrows an arbitrary page size to a supported option. */
export function isAuditPageSize(value: number): value is AuditPageSize {
  return (AUDIT_PAGE_SIZE_OPTIONS as readonly number[]).includes(value);
}
