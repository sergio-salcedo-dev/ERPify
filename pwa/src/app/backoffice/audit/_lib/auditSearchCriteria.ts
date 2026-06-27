import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { isUuid } from "@/context/shared/uuid/infrastructure/isUuid";
import type { Filter } from "@/context/shared/search/domain";
import type { SortDirection } from "@/context/shared/search/domain/SortDirection";
import type {
  AuditSort,
  AuditTimelineCriteria,
} from "@/context/backoffice/audit/domain/AuditTimelineRepository";
import type { AuditFilter } from "./auditFilter";

/** The single sortable axis of the 4.1 read model (the keyset key); any other field is a 422. */
const OCCURRED_ON_FIELD = "occurredOn";

/**
 * Maps the audit UI filter state to the generic `Filter[]` vocabulary the API understands. Each axis
 * lands on its allow-listed server field/operator (`AuditTimelineFilterApplier`):
 * `level`/`actorType`/`resourceType` → `eq`, `action` → `contains`, the date range → inclusive
 * `gte`/`lte` on `occurredOn`. `actorId`/`resourceId` are UUID-only on the wire, so a half-typed id
 * is omitted rather than fired as a guaranteed 422 — the same "no bound for a mid-edit value" stance
 * the bank filters take.
 */
export function toAuditFilters(filter: AuditFilter): Filter[] {
  const filters: Filter[] = [];

  const level = filter.level.trim();
  if (level) filters.push({ field: "level", operator: "eq", value: level });

  const actorType = filter.actorType.trim();
  if (actorType) filters.push({ field: "actorType", operator: "eq", value: actorType });

  const actorId = filter.actorId.trim();
  if (isUuid(actorId)) filters.push({ field: "actorId", operator: "eq", value: actorId });

  const resourceType = filter.resourceType.trim();
  if (resourceType) filters.push({ field: "resourceType", operator: "eq", value: resourceType });

  const resourceId = filter.resourceId.trim();
  if (isUuid(resourceId)) filters.push({ field: "resourceId", operator: "eq", value: resourceId });

  const correlationId = filter.correlationId.trim();
  if (isUuid(correlationId)) {
    filters.push({ field: "correlationId", operator: "eq", value: correlationId });
  }

  const action = filter.action.trim();
  if (action) filters.push({ field: "action", operator: "contains", value: action });

  const from = occurredOnBound(filter.from, "start");
  if (from) filters.push({ field: OCCURRED_ON_FIELD, operator: "gte", value: from });

  const to = occurredOnBound(filter.to, "end");
  if (to) filters.push({ field: OCCURRED_ON_FIELD, operator: "lte", value: to });

  return filters;
}

/** The timeline's only sort axis, defaulting to newest-first when no direction is supplied. */
export function toAuditSort(direction: SortDirection): AuditSort {
  return { field: OCCURRED_ON_FIELD, direction };
}

export function toAuditCriteria(
  filter: AuditFilter,
  direction: SortDirection,
  limit: number,
): AuditTimelineCriteria {
  return { filters: toAuditFilters(filter), sort: toAuditSort(direction), limit };
}

/**
 * Converts a `dd/mm/yyyy` date-field value into an inclusive ISO-8601 bound on `occurred_on`:
 * start-of-day for the lower bound, end-of-day for the upper, both in the viewer's local zone.
 * Returns null when empty or not a complete, real calendar day (so a half-typed value is "no bound").
 */
function occurredOnBound(value: string, edge: "start" | "end"): string | null {
  const date = dateTimeProvider.parseDdMmYyyy(value.trim());
  if (!date) return null;
  const bounded =
    edge === "start" ? dateTimeProvider.startOfDay(date) : dateTimeProvider.endOfDay(date);
  return dateTimeProvider.formatToISO(bounded);
}
