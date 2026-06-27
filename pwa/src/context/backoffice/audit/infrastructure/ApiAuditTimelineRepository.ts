import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { buildSearchParams } from "@/context/shared/search/infrastructure";
import { WIRE_MAX_LIMIT, type PageEnvelope } from "@/context/shared/search/domain";
import type { AuditEntry } from "../domain/AuditEntry";
import type {
  AuditTimelineCriteria,
  AuditTimelinePage,
  AuditTimelineRepository,
} from "../domain/AuditTimelineRepository";

interface AuditTimelineResponse {
  data: AuditEntry[];
  pagination: PageEnvelope;
}

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function isStringOrNull(value: unknown): value is string | null {
  return value === null || typeof value === "string";
}

function isNumberOrNull(value: unknown): value is number | null {
  return value === null || typeof value === "number";
}

/**
 * Validates one timeline row. Every field is checked at the trust boundary so a contract drift
 * surfaces as a typed failure (MALFORMED_RESPONSE_ENVELOPE) instead of a silent mismap. `level` and
 * `actorType` are accepted as any string by design — the read model is forensic and never narrows
 * them to a closed set (an unknown token must still render, not break the investigation).
 */
function isAuditEntry(value: unknown): value is AuditEntry {
  return (
    isObjectRecord(value) &&
    typeof value.id === "string" &&
    typeof value.occurredOn === "string" &&
    typeof value.level === "string" &&
    typeof value.action === "string" &&
    typeof value.actorType === "string" &&
    isStringOrNull(value.actorId) &&
    typeof value.correlationId === "string" &&
    isStringOrNull(value.resourceType) &&
    isStringOrNull(value.resourceId) &&
    typeof value.actorErased === "boolean"
  );
}

/**
 * The adapter's trust boundary. Accepts ONLY the cursor-only envelope (`hasNext`/`hasPrev`/`count`/
 * `links`); a server still on a legacy page-based shape surfaces as a typed failure. Shared by the
 * query adapter and the navigation adapter ({@link ApiAuditTimelineNavigator}).
 */
export function isAuditTimelineResponse(value: unknown): value is AuditTimelineResponse {
  if (!isObjectRecord(value) || !Array.isArray(value.data)) {
    return false;
  }
  const { data, pagination } = value;
  if (!data.every(isAuditEntry) || !isObjectRecord(pagination)) {
    return false;
  }
  const { hasNext, hasPrev, count, links } = pagination;
  return (
    typeof hasNext === "boolean" &&
    typeof hasPrev === "boolean" &&
    isNumberOrNull(count) &&
    isObjectRecord(links) &&
    isStringOrNull(links.next) &&
    isStringOrNull(links.prev)
  );
}

/**
 * Maps a validated response to the domain page: the rows reconstructed to the exact `AuditEntry`
 * shape (dropping any stray field) plus the envelope carried through verbatim — the `links` are
 * server-composed and never rebuilt. Shared so the query and navigation adapters map identically.
 */
export function toAuditTimelinePage(response: AuditTimelineResponse): AuditTimelinePage {
  return {
    entries: response.data.map(toAuditEntry),
    hasNext: response.pagination.hasNext,
    hasPrev: response.pagination.hasPrev,
    count: response.pagination.count,
    links: response.pagination.links,
  };
}

function toAuditEntry(row: AuditEntry): AuditEntry {
  return {
    id: row.id,
    occurredOn: row.occurredOn,
    level: row.level,
    action: row.action,
    actorType: row.actorType,
    actorId: row.actorId,
    correlationId: row.correlationId,
    resourceType: row.resourceType,
    resourceId: row.resourceId,
    actorErased: row.actorErased,
  };
}

/**
 * HTTP adapter for the read-only {@link AuditTimelineRepository} over `GET /audit/timeline`. Mirrors
 * the bank list adapter: the first page serializes filters + sort + limit and NEVER a cursor —
 * continuing a page is {@link ApiAuditTimelineNavigator.follow} on a server link (one navigation
 * authority: the envelope `links`).
 */
@injectable()
export class ApiAuditTimelineRepository implements AuditTimelineRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async search(criteria: AuditTimelineCriteria): Promise<AuditTimelinePage> {
    const params = buildSearchParams(criteria.filters);
    if (criteria.sort) {
      params.append("sort", criteria.sort.field);
      // The API sort enum is uppercase (`ASC`/`DESC`); the PWA enum is lowercase.
      params.append("direction", criteria.sort.direction.toUpperCase());
    }
    // Clamp to the wire ceiling: the UI can never emit `limit > 100` (hard client
    // enforcement complementary to the backend 422). No `paginationMode` → the API
    // defaults to LIGHT (skips COUNT; `count` is null) — the timeline navigates by cursor.
    params.append("limit", String(Math.min(criteria.limit, WIRE_MAX_LIMIT)));

    const response = await this.httpClient.get(
      `${API_ENDPOINTS.BACKOFFICE.AUDIT.TIMELINE}?${params.toString()}`,
      isAuditTimelineResponse,
    );

    return toAuditTimelinePage(response);
  }
}
