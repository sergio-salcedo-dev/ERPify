<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Override;
use Throwable;

/**
 * In-memory {@see AuditLogWriter} that records each written entry keyed by id — so a redelivery of the
 * same entry is a no-op, mirroring the real `ON CONFLICT (id) DO NOTHING` — or, when constructed with a
 * failure, always throws, to exercise the security branch's propagation.
 *
 * @internal
 */
final class InMemoryAuditLogWriter implements AuditLogWriter
{
    /** @var array<string, AuditLogEntry> */
    public array $written = [];

    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    #[Override]
    public function write(AuditLogEntry $entry): void
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        $this->written[$entry->id] = $entry;
    }
}
