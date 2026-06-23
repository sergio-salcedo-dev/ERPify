<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Messenger;

use Erpify\Shared\Audit\Application\AuditLogWriter;
use Erpify\Shared\Audit\Application\RecordAuditEntry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Drains the dedicated `audit` transport: the worker delivers a {@see RecordAuditEntry} and this writes
 * its entry. Intentionally dumb — a redelivery is a silent no-op via the writer's `ON CONFLICT (id) DO
 * NOTHING`, so it adds no deduplication of its own. It depends only on {@see AuditLogWriter}: the actor
 * was already sealed in the request cycle and travels inside the entry, so re-resolving it here
 * (off-request) would mislabel every activity row as system.
 */
#[AsMessageHandler]
final readonly class RecordAuditEntryHandler
{
    public function __construct(private AuditLogWriter $writer)
    {
    }

    public function __invoke(RecordAuditEntry $message): void
    {
        $this->writer->write($message->entry);
    }
}
