import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import {
  AuditWriteOperation,
  type AuditChanges,
  type AuditEventDetail,
  type AuditFieldChange,
  type AuditFieldValue,
  type AuditScalar,
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

/** Narrows an unknown diff side to a valid {@link AuditFieldValue}. */
function isAuditFieldValue(value: unknown): value is AuditFieldValue {
  return isAuditScalarOrNull(value) || isAuditSealedValue(value);
}

/** A `{ old, new }` pair where both sides are a scalar, `null` or a sealed value — anything else is drift. */
function isAuditFieldChange(value: unknown): value is AuditFieldChange {
  return isObjectRecord(value) && isAuditFieldValue(value.old) && isAuditFieldValue(value.new);
}

function isAuditChanges(value: unknown): value is AuditChanges {
  return isObjectRecord(value) && Object.values(value).every(isAuditFieldChange);
}

const AUDIT_WRITE_OPERATIONS: ReadonlySet<string> = new Set(Object.values(AuditWriteOperation));

function isAuditWriteOperation(value: unknown): value is AuditWriteOperation {
  return typeof value === "string" && AUDIT_WRITE_OPERATIONS.has(value);
}

/**
 * `metadata` must be an object, and when it carries `changes` that map must be a well-formed diff. A row
 * with no diff (an access-log read) carries `{}`/other keys and still validates — both are optional, the
 * object is not.
 *
 * **`operation` is deliberately NOT validated here.** It is an enum the API owns, so a release that adds a
 * fourth kind reaches this client before the client knows the name; rejecting the row would turn a value the
 * UI does not need into a `MALFORMED_RESPONSE_ENVELOPE` over the whole event, diff included. The domain
 * already prescribes the answer for a value this side cannot place — treat it as unknown, never as a fourth
 * kind — and {@link toMetadata} applies it by dropping the unrecognised value from the typed slot, so the
 * header renders as silence exactly as it does for a row that carries no operation at all.
 */
function isAuditEventMetadata(
  value: unknown,
): value is { changes?: AuditChanges; operation?: unknown } & Record<string, unknown> {
  if (!isObjectRecord(value)) return false;
  return !("changes" in value) || isAuditChanges(value.changes);
}

/**
 * Validates the un-enveloped row: the slim fields as the timeline guard checks them
 * (`level`/`actorType` accepted as any string — the read model is forensic and never narrows them)
 * plus the full `metadata`/diff shape.
 */
function isAuditEventDetailRow(value: unknown): value is AuditEventDetail {
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
    typeof value.resourceErased === "boolean" &&
    isAuditEventMetadata(value.metadata)
  );
}

interface AuditEventDetailResponse {
  data: AuditEventDetail;
}

/**
 * The adapter's trust boundary for the detail resource — `GET /audit/events/{id}` wraps the row in a
 * `data` envelope, like every other resource endpoint here, so a contract drift (including the bare
 * row this guard used to expect) surfaces as a typed failure (MALFORMED_RESPONSE_ENVELOPE) rather
 * than a silent mismap.
 */
export function isAuditEventDetailResponse(value: unknown): value is AuditEventDetailResponse {
  return isObjectRecord(value) && isAuditEventDetailRow(value.data);
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
function normalizeFieldValue(value: AuditFieldValue): AuditFieldValue {
  return isAuditSealedValue(value) ? { __enc__: value.__enc__ } : value;
}

/**
 * Carries `metadata` through verbatim (forensic fidelity) but normalises `changes` when present, and drops
 * an `operation` this client cannot place. The cost is stated rather than hidden: the raw value of a fourth
 * kind does not reach the UI. The alternative is worse in both directions — typing the slot as a plain
 * string pushes the unknown into every consumer that indexes it, and keeping it typed while it holds a
 * value the type does not admit is the lie the type exists to prevent.
 */
function toMetadata(
  metadata: { changes?: AuditChanges; operation?: unknown } & Record<string, unknown>,
): AuditEventDetail["metadata"] {
  const { operation, ...rest } = metadata;
  const placed = isAuditWriteOperation(operation) ? { ...rest, operation } : rest;

  if (!isAuditChanges(placed.changes)) return { ...placed };
  return { ...placed, changes: toAuditChanges(placed.changes) };
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
    resourceErased: detail.resourceErased,
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
    const response = await this.httpClient.get(
      API_ENDPOINTS.BACKOFFICE.AUDIT.EVENT_DETAIL(id),
      isAuditEventDetailResponse,
    );
    return toAuditEventDetail(response.data);
  }
}
