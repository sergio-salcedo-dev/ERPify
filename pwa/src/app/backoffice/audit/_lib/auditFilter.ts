import { AuditLevel } from "@/context/backoffice/audit/domain/AuditEntry";
import { isUuid } from "@/context/shared/uuid/infrastructure/isUuid";

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

/** Render modes of the single screen — Timeline (chronological) or Journey (grouped by correlation). */
export const AuditView = {
  Timeline: "timeline",
  Journey: "journey",
} as const;

export type AuditView = (typeof AuditView)[keyof typeof AuditView];

export function isAuditView(value: string): value is AuditView {
  return value === AuditView.Timeline || value === AuditView.Journey;
}

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

/**
 * A fixed actor — type + a resolvable UUID id — is the precondition for the Journey render mode
 * (UX-DR3). The id must pass the same `isUuid` gate `toAuditFilters` applies to the wire, so the mode
 * turns on only when the query genuinely pins one actor; a half-typed id would otherwise reconstruct
 * a "session" spanning every actor of that type.
 */
export function hasFixedActor(filter: AuditFilter): boolean {
  return Boolean(filter.actorType.trim()) && isUuid(filter.actorId.trim());
}
