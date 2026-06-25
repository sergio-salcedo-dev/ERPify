<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\AuditLogPruner;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Override;

/**
 * Captures every {@see AuditLogPruner::pruneOlderThan()} call so a handler test can assert the per-level
 * thresholds without a database.
 *
 * @internal
 */
final class RecordingAuditLogPruner implements AuditLogPruner
{
    /** @var list<array{level: AuditLevel, threshold: DateTimeImmutable}> */
    public array $calls = [];

    #[Override]
    public function pruneOlderThan(AuditLevel $level, DateTimeImmutable $threshold): int
    {
        $this->calls[] = ['level' => $level, 'threshold' => $threshold];

        return 0;
    }
}
