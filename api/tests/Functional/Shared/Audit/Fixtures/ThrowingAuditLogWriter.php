<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit\Fixtures;

use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Override;
use RuntimeException;

/**
 * An {@see AuditLogWriter} standing in for an unreachable `audit_log`: every write raises, so a functional test
 * can drive the best-effort failure branch end-to-end without taking the database down.
 *
 * It counts its calls because the claim under test is about a line ARRIVING, and an arrival assertion over a
 * write that never happened would pass for the wrong reason — the count is what makes the absence of the line
 * mean "discarded" rather than "never attempted".
 *
 * @internal
 */
final class ThrowingAuditLogWriter implements AuditLogWriter
{
    public int $attempts = 0;

    #[Override]
    public function write(AuditLogEntry $entry): void
    {
        ++$this->attempts;

        throw new RuntimeException('audit_log unavailable');
    }
}
