<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Domain;

use DateTimeImmutable;

/**
 * The full read projection of one `audit_log` record — the canonical audit event behind
 * `GET /audit/events/{id}`, distinct from the slim {@see AuditTimelineEntry} the keyset listing
 * returns. It carries the same forensic fields plus the decoded `metadata`: for a `change` row the
 * shape is `{changes: {field: {old, new}}}`, the field-by-field diff the investigation UI renders.
 *
 * As with {@see AuditTimelineEntry}, `level` and `actorType` stay the raw stored strings (never the
 * `AuditLevel`/`ActorType` enums) — a forensic read reflects whatever the table holds and must never
 * raise on an unexpected token while investigating. `metadata` is attacker-influenced (an editable
 * bank name reaches it through the diff): treat it as tainted downstream — escape it on render, never
 * use it in a trust decision. `ip`/`user_agent` are deliberately absent — both are PII and the route
 * is public pre-auth, so the projection is diff-only.
 */
final readonly class AuditEventDetail
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $occurredOn,
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
