<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Messenger\Maintenance;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance\PruneAuditLogHandler;
use Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance\PruneAuditLogMessage;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedClock;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogPruner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PruneAuditLogHandler::class)]
final class PruneAuditLogHandlerTest extends TestCase
{
    #[Test]
    public function itPrunesEveryLevelAtItsOwnThresholdFromTheClock(): void
    {
        $pruner = new RecordingAuditLogPruner();
        $clock = new FixedClock(new DateTimeImmutable('2026-06-25 12:00:00'));

        (new PruneAuditLogHandler($pruner, $clock))(
            new PruneAuditLogMessage(activityRetentionDays: 90, securityRetentionDays: 365),
        );

        $this->assertCount(2, $pruner->calls, 'one prune per audit level');

        $byLevel = [];

        foreach ($pruner->calls as $call) {
            $byLevel[$call['level']->value] = $call['threshold']->format('Y-m-d H:i:s');
        }

        $this->assertSame('2026-03-27 12:00:00', $byLevel[AuditLevel::ACTIVITY->value] ?? null);
        $this->assertSame('2025-06-25 12:00:00', $byLevel[AuditLevel::SECURITY->value] ?? null);
    }
}
