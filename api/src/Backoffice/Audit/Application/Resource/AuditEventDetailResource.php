<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Application\Resource;

use ArrayObject;

/**
 * Wire contract of the audit-event detail (`GET /audit/events/{id}`). The serialized object is this
 * DTO, never an entity. The leading fields mirror the timeline row — including both erasure booleans,
 * so a consumer can tell an anonymisation pseudonym from a live identifier on either axis; `metadata`
 * is the extra — the decoded JSONB diff (`change` rows: `{changes: {field: {old, new}}}`), already in
 * normalized form so it emits verbatim as a JSON object.
 *
 * `ip`/`userAgent` are intentionally absent — both are PII and the detail payload is diff-only.
 * `metadata` is tainted (it can hold an editable bank name); consumers escape it and never feed it to a
 * trust decision.
 *
 * One constructor parameter per field of the row it projects, which is what a flat, scalar-only wire
 * contract is: grouping any of them into an object to satisfy a parameter count would nest the JSON and
 * change the contract for a metric's sake.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final readonly class AuditEventDetailResource
{
    /**
     * `metadata` is an {@see ArrayObject} rather than an `array` because an empty PHP array encodes as
     * `[]`, and this field is a MAP on the wire: the client's guard admits an object and rejects an array,
     * so an entry carrying no metadata — the bulk of the `activity` timeline — would fail the envelope and
     * blank the drawer with no visible error. `ArrayObject` is the one shape that survives normalization as
     * an object, and only while the normalizer preserves it; {@see ResourceNormalizer} is where that is set.
     * The same holds one level down: `changes`, when present, is a map of field to `{old, new}` and the
     * guard refuses it as an array, so the mapper hands it over as an `ArrayObject` too — an empty diff
     * then reaches the wire as `"changes":{}` rather than failing the whole envelope.
     *
     * @param ArrayObject<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $occurredOn,
        public string $level,
        public string $action,
        public string $actorType,
        public ?string $actorId,
        public string $correlationId,
        public ?string $resourceType,
        public ?string $resourceId,
        public bool $actorErased,
        public bool $resourceErased,
        public ArrayObject $metadata,
    ) {
    }
}
