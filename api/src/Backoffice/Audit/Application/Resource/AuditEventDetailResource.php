<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Application\Resource;

/**
 * Wire contract of the audit-event detail (`GET /audit/events/{id}`). The serialized object is this
 * DTO, never an entity. The first ten fields mirror the timeline row; `metadata` is the extra — the
 * decoded JSONB diff (`change` rows: `{changes: {field: {old, new}}}`), already in normalized form so
 * it emits verbatim as a JSON object.
 *
 * `ip`/`userAgent` are intentionally absent — both are PII and the detail payload is diff-only.
 * `metadata` is tainted (it can hold an editable bank name); consumers escape it and never feed it to a
 * trust decision.
 */
final readonly class AuditEventDetailResource
{
    /**
     * @param array<string, mixed> $metadata
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
        public array $metadata,
    ) {
    }
}
