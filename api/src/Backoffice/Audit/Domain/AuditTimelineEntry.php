<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Domain;

use DateTimeImmutable;

/**
 * One row of the audit investigation timeline — a read-only projection of an `audit_log` record.
 *
 * Backoffice consumes the audit trail, never writes it: this is a query result, not the write-side
 * {@see \Erpify\Shared\Audit\Application\AuditLogEntry} aggregate. `level` and `actorType` are kept
 * as the raw stored strings (not the `AuditLevel`/`ActorType` enums) on purpose — a forensic read
 * must reflect faithfully whatever the table holds, never raise on an unexpected token while
 * investigating. `occurredOn` carries full `TIMESTAMPTZ(6)` precision (the forensic instant and the
 * keyset ordering key); the wire formatting is the resource mapper's job. `actorErased` is the
 * materialised GDPR-erasure state — a boolean flag, never derived at read time.
 */
final readonly class AuditTimelineEntry
{
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
        public bool $resourceErased,
    ) {
    }
}
