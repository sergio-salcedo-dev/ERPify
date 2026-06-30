import { AuditLevel } from "@/context/backoffice/audit/domain/AuditEntry";
import { isUuid } from "@/context/shared/uuid/infrastructure/isUuid";

/**
 * The audit timeline's UI filter state. Every field lives in URL params (never localStorage): an
 * `actorId`/`resourceId` is PII, and the URL keeps the investigation shareable for a ticket without
 * persisting an identifier on the device. Empty string means "no constraint on this axis".
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
  /** Set only by the "Ver esta correlación" pivot / a deep link — no dedicated panel input. */
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

/** Render modes of the single screen — Timeline (chronological) or Jornada (grouped by correlation). */
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
  { value: "", label: "Todo" },
  { value: AuditLevel.Activity, label: "Activity" },
  { value: AuditLevel.Security, label: "Security" },
  { value: AuditLevel.Change, label: "Cambios" },
];

/** True when `value` is a level the segmented control can represent ("" = no level filter). */
export function isAuditLevelValue(value: string): boolean {
  return AUDIT_LEVEL_SEGMENTS.some((segment) => segment.value === value);
}

/**
 * Count of populated panel-hosted filters (actor + recurso + acción). Level and the date range live
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

/** True when ANY filter axis is populated — drives empty-state copy and the "Limpiar filtros" path. */
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
 * A fixed actor — type + a resolvable UUID id — is the precondition for the Jornada render mode
 * (UX-DR3). The id must pass the same `isUuid` gate `toAuditFilters` applies to the wire, so the mode
 * turns on only when the query genuinely pins one actor; a half-typed id would otherwise reconstruct
 * a "session" spanning every actor of that type.
 */
export function hasFixedActor(filter: AuditFilter): boolean {
  return Boolean(filter.actorType.trim()) && isUuid(filter.actorId.trim());
}
