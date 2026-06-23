<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

/**
 * Internal transport message carrying one {@see AuditLogEntry} to the audit worker. It
 * wraps the record rather than duplicating its fields, so the handler writes `$message->entry`
 * with no re-mapping.
 *
 * It deliberately has no base class and is not a domain event, which is what keeps it off the
 * event backbone — never written to the `event_store`, never seen by another context's stream.
 */
final readonly class RecordAuditEntry
{
    public function __construct(public AuditLogEntry $entry)
    {
    }
}
