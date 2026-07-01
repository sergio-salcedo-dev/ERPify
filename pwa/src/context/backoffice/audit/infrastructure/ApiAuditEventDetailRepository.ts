import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import {
  type AuditChanges,
  type AuditEventDetail,
  type AuditFieldChange,
  type AuditScalar,
  type AuditSealedValue,
  isAuditSealedValue,
} from "../domain/AuditChange";
import type { AuditEventDetailRepository } from "../domain/AuditEventDetailRepository";

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isStringOrNull(value: unknown): value is string | null {
  return value === null || typeof value === "string";
}

function isAuditScalarOrNull(value: unknown): value is AuditScalar | null {
  return (
    value === null ||
    typeof value === "string" ||
    typeof value === "number" ||
    typeof value === "boolean"
  );
}

/** A scalar, a real `null`, or a crypto-shredded sealed value — the three shapes a diff side can take. */
function isAuditFieldValue(value: unknown): value is AuditScalar | AuditSealedValue | null {
  return isAuditScalarOrNull(value) || isAuditSealedValue(value);
}

/** A `{ old, new }` pair where both sides are a scalar, `null` or a sealed value — anything else is drift. */
function isAuditFieldChange(value: unknown): value is AuditFieldChange {
  return isObjectRecord(value) && isAuditFieldValue(value.old) && isAuditFieldValue(value.new);
}

function isAuditChanges(value: unknown): value is AuditChanges {
  return isObjectRecord(value) && Object.values(value).every(isAuditFieldChange);
}

/**
 * `metadata` must be an object; when it carries `changes` that map must be a well-formed diff. A row
 * with no diff (an access-log read) carries `{}`/other keys and still validates — the diff is
 * optional, the object is not.
 */
function isAuditEventMetadata(
  value: unknown,
): value is { changes?: AuditChanges } & Record<string, unknown> {
  if (!isObjectRecord(value)) return false;
  return !("changes" in value) || isAuditChanges(value.changes);
}

/**
 * The adapter's trust boundary for the detail resource. Validates the slim fields exactly as the
 * timeline guard does (`level`/`actorType` accepted as any string — the read model is forensic and
 * never narrows them) plus the `metadata`/diff shape, so a contract drift surfaces as a typed
 * failure (MALFORMED_RESPONSE_ENVELOPE) rather than a silent mismap.
 */
export function isAuditEventDetailResponse(value: unknown): value is AuditEventDetail {
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
    typeof value.actorErased === "boolean" &&
    isAuditEventMetadata(value.metadata)
  );
}

/** Rebuilds each `{ old, new }` pair to drop any stray field a tampered/extended payload might carry. */
function toAuditChanges(changes: AuditChanges): AuditChanges {
  const result: AuditChanges = {};
  for (const [field, change] of Object.entries(changes)) {
    result[field] = {
      old: normalizeFieldValue(change.old),
      new: normalizeFieldValue(change.new),
    };
  }
  return result;
}

/** A sealed value is reduced to its marker alone, so a stray key on the ciphertext object cannot ride in. */
function normalizeFieldValue(
  value: AuditScalar | AuditSealedValue | null,
): AuditScalar | AuditSealedValue | null {
  return isAuditSealedValue(value) ? { __enc__: value.__enc__ } : value;
}

/** Carries `metadata` through verbatim (forensic fidelity) but normalises `changes` when present. */
function toMetadata(
  metadata: { changes?: AuditChanges } & Record<string, unknown>,
): AuditEventDetail["metadata"] {
  if (!isAuditChanges(metadata.changes)) return { ...metadata };
  return { ...metadata, changes: toAuditChanges(metadata.changes) };
}

function toAuditEventDetail(detail: AuditEventDetail): AuditEventDetail {
  return {
    id: detail.id,
    occurredOn: detail.occurredOn,
    level: detail.level,
    action: detail.action,
    actorType: detail.actorType,
    actorId: detail.actorId,
    correlationId: detail.correlationId,
    resourceType: detail.resourceType,
    resourceId: detail.resourceId,
    actorErased: detail.actorErased,
    metadata: toMetadata(detail.metadata),
  };
}

/**
 * HTTP adapter for the read-only {@link AuditEventDetailRepository} over `GET /audit/events/{id}`.
 * Mirrors {@link ApiAuditTimelineRepository}: validate at the boundary, then reconstruct the exact
 * domain shape (dropping any stray field). The detail is a sibling resource of the timeline, never a
 * row of it.
 */
@injectable()
export class ApiAuditEventDetailRepository implements AuditEventDetailRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async findById(id: string): Promise<AuditEventDetail> {
    const detail = await this.httpClient.get(
      API_ENDPOINTS.BACKOFFICE.AUDIT.EVENT_DETAIL(id),
      isAuditEventDetailResponse,
    );
    return toAuditEventDetail(detail);
  }
}
