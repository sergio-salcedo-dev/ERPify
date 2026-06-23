<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

/**
 * Internal transport message carrying one {@see AuditLogEntry} to the audit worker. It
 * wraps the record rather than duplicating its fields, so the handler writes `$message->entry`
 * with no re-mapping — the same shape a domain event uses to compose its snapshot.
 *
 * It deliberately has no base class and is not a domain event: that non-inheritance is the
 * complete mechanism that keeps it off the event backbone — undiscovered by the event
 * registration pass, ignored by the persistence middleware, and rejected by the event bus
 * signature — so it never reaches the `event_store` or another context's stream.
 */
final readonly class RecordAuditEntry
{
    public function __construct(public AuditLogEntry $entry)
    {
    }
}
