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

/**
 * Absent or a boolean — nothing else. The tolerance is for ABSENCE only: a field that is present
 * but not a boolean is drift and still rejects, because the value would then be READ as a state.
 */
function isOptionalBoolean(value: unknown): value is boolean | undefined {
  return value === undefined || typeof value === "boolean";
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

/**
 * `metadata` as the guard admits it: `changes` is a well-formed diff, or a `null`/scalar the client degrades
 * to an unreadable diff (see {@link isAuditEventMetadata}); `operation` is anything, placed downstream.
 */
type AuditEventMetadataWire = {
  changes?: AuditChanges | AuditScalar | null;
  operation?: unknown;
} & Record<string, unknown>;

const AUDIT_WRITE_OPERATIONS: ReadonlySet<string> = new Set(Object.values(AuditWriteOperation));

function isAuditWriteOperation(value: unknown): value is AuditWriteOperation {
  return typeof value === "string" && AUDIT_WRITE_OPERATIONS.has(value);
}

/**
 * `metadata` must be an object, and when it carries `changes` that map must be a well-formed diff. A row
 * with no diff (an access-log read) carries `{}`/other keys and still validates — both are optional, the
 * object is not.
 *
 * **A `changes` that is `null` or a scalar degrades to an unreadable diff instead of rejecting.** The API
 * never serves the content of a `changes` that is not a map — a scalar arrives as the withheld marker, a
 * non-empty list as a list of the same length holding only that marker, `null` as `null` — but it keeps the
 * shape, and the shape is the corruption signal this guard reads, so the split below holds unchanged. A
 * `null` or a scalar carries no `{old, new}` pair, and refusing it would lose the whole event over a field
 * the UI only uses to paint the diff. A list or a malformed map still rejects: a list in that position is a
 * failure of the diff builder — pairs that lost their field name — and degrading it to an unreadable diff
 * would present a row whose shape can no longer be believed as a minor gap; the full reasoning is the audit
 * ADR (`docs/adr/audit-activity-log.md`, D4). {@link toMetadata} removes the value from the typed slot, and
 * {@link toAuditEventDetail} flags the detail `changesUnreadable`.
 *
 * **`operation` is deliberately NOT validated here.** It is an enum the API owns, so a release that adds a
 * fourth kind reaches this client before the client knows the name; rejecting the row would turn a value the
 * UI does not need into a `MALFORMED_RESPONSE_ENVELOPE` over the whole event, diff included. The domain
 * already prescribes the answer for a value this side cannot place — treat it as unknown, never as a fourth
 * kind — and {@link toMetadata} applies it by dropping the unrecognised value from the typed slot, so the
 * header renders as silence exactly as it does for a row that carries no operation at all.
 */
function isAuditEventMetadata(value: unknown): value is AuditEventMetadataWire {
  if (!isObjectRecord(value)) return false;
  return (
    !("changes" in value) || isAuditScalarOrNull(value.changes) || isAuditChanges(value.changes)
  );
}

/**
 * The row as it arrives on the wire. Identical to {@link AuditEventDetail} except that
 * `resourceErased` may be absent — see {@link isAuditEventDetailRow}.
 */
type AuditEventDetailWire = Omit<AuditEventDetail, "resourceErased" | "metadata"> & {
  resourceErased?: boolean;
  metadata: AuditEventMetadataWire;
};

/**
 * Validates the un-enveloped row: the slim fields as the timeline guard checks them
 * (`level`/`actorType` accepted as any string — the read model is forensic and never narrows them)
 * plus the full `metadata`/diff shape.
 *
 * `resourceErased` is the one field whose ABSENCE is tolerated, mirroring the timeline guard: it
 * identifies nothing and only drives an "erased" marker plus the withheld follow-resource pivot, so
 * a client meeting an API that does not publish it must not lose the whole event — diff included —
 * over a presentational boolean. Missing reads as `true`, in the safe direction and not the
 * convenient one: an absent flag defaulted to `false` would offer the follow-resource pivot on a
 * pseudonym for the whole window. A present non-boolean is still drift and still rejects, because
 * such a value would be READ as a state.
 */
function isAuditEventDetailRow(value: unknown): value is AuditEventDetailWire {
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
    isOptionalBoolean(value.resourceErased) &&
    isAuditEventMetadata(value.metadata)
  );
}

interface AuditEventDetailResponse {
  data: AuditEventDetailWire;
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
 * Carries the other `metadata` keys through verbatim (forensic fidelity); `changes` is kept, normalised, only
 * when it is a diff — a `null`/scalar is removed and reported as `changesUnreadable` on the detail instead —
 * and an `operation` this client cannot place is dropped. The cost is stated rather than hidden: neither the
 * raw corrupt `changes` nor the raw value of a fourth kind reaches the UI. Keeping either in a typed slot
 * that does not admit it is the lie the type exists to prevent, and widening the slot pushes the unknown
 * into every consumer that indexes it.
 */
function toMetadata(metadata: AuditEventMetadataWire): AuditEventDetail["metadata"] {
  const { operation, changes, ...rest } = metadata;
  const placed = isAuditWriteOperation(operation) ? { ...rest, operation } : rest;

  if (!isAuditChanges(changes)) return placed;
  return { ...placed, changes: toAuditChanges(changes) };
}

function toAuditEventDetail(detail: AuditEventDetailWire): AuditEventDetail {
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
    resourceErased: detail.resourceErased ?? true,
    metadata: toMetadata(detail.metadata),
    ...(hasUnreadableChanges(detail.metadata) ? { changesUnreadable: true } : {}),
  };
}

/** A `changes` key the row carries but that is not a diff — the guard admitted it only as a `null`/scalar. */
function hasUnreadableChanges(metadata: AuditEventMetadataWire): boolean {
  return "changes" in metadata && !isAuditChanges(metadata.changes);
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
