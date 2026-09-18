import { AuditLevel } from "@/context/backoffice/audit/domain/AuditEntry";

/**
 * The audit timeline's UI filter state. Every field lives in URL params, never localStorage: an
 * `actorId`/`resourceId` identifies a person, so the address bar carries it — that is what keeps the
 * investigation shareable for a ticket — while no device storage the app controls does. The logs that
 * see it redact it: Caddy's access log at the edge (`api/frankenphp/Caddyfile`) and a Monolog
 * processor in the application. One sink is recorded as still open in
 * `PRODUCTION_SECURITY_CHECKLIST.md` §7. Empty string means "no constraint on this axis".
 *
 * `from`/`to` are `dd/mm/yyyy` (the `<DateField>` format); the rest are raw strings forwarded to the
 * server filters by {@link toAuditFilters}.
 */
export interface AuditFilter {
  level: string;
  from: string;
  to: string;
  actorType: string;
  actorId: string;
  resourceType: string;
  resourceId: string;
  action: string;
  /** Set only by the "Follow this correlation" pivot / a deep link — no dedicated panel input. */
  correlationId: string;
}

export const EMPTY_AUDIT_FILTER: AuditFilter = {
  level: "",
  from: "",
  to: "",
  actorType: "",
  actorId: "",
  resourceType: "",
  resourceId: "",
  action: "",
  correlationId: "",
};

/** The segmented level control's options. The empty-value segment applies no level filter. */
export const AUDIT_LEVEL_SEGMENTS: ReadonlyArray<{ value: string; label: string }> = [
  { value: "", label: "All" },
  { value: AuditLevel.Activity, label: "Activity" },
  { value: AuditLevel.Security, label: "Security" },
  { value: AuditLevel.Change, label: "Change" },
];

/** True when `value` is a level the segmented control can represent ("" = no level filter). */
export function isAuditLevelValue(value: string): boolean {
  return AUDIT_LEVEL_SEGMENTS.some((segment) => segment.value === value);
}

/**
 * Count of populated panel-hosted filters (actor + resource + action). Level and the date range live
 * in the always-visible bar, so the "Filtros (n)" badge only counts what a collapsed panel hides.
 */
export function countPanelFilters(filter: AuditFilter): number {
  let count = 0;
  if (filter.actorType.trim()) count += 1;
  if (filter.actorId.trim()) count += 1;
  if (filter.resourceType.trim()) count += 1;
  if (filter.resourceId.trim()) count += 1;
  if (filter.action.trim()) count += 1;
  return count;
}

/** True when ANY filter axis is populated — drives empty-state copy and the "Clear filters" path. */
export function hasActiveAuditFilter(filter: AuditFilter): boolean {
  return (
    Boolean(filter.level.trim()) ||
    Boolean(filter.from.trim()) ||
    Boolean(filter.to.trim()) ||
    Boolean(filter.correlationId.trim()) ||
    countPanelFilters(filter) > 0
  );
}
