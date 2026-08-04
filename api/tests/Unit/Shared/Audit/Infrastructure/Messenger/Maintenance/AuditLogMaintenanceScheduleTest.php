<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance\AuditLogMaintenanceSchedule;
use Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance\PruneAuditLogMessage;
use Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance\ReconcileSubjectErasuresMessage;
use Erpify\Tests\Support\ScheduledTicks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @internal
 */
#[CoversClass(AuditLogMaintenanceSchedule::class)]
final class AuditLogMaintenanceScheduleTest extends TestCase
{
    #[Test]
    public function itSchedulesTheRetentionPruneAndTheErasureReconciliationDaily(): void
    {
        $schedule = (new AuditLogMaintenanceSchedule(new ArrayAdapter()))->getSchedule();

        // Both facts each tick encodes, not their count: a count survives the payload being swapped and
        // survives `1 day` widening to `1 year`, which are the two ways a schedule quietly stops being what
        // it is named after.
        $this->assertSame(
            [
                PruneAuditLogMessage::class . ' @ every 1 day',
                ReconcileSubjectErasuresMessage::class . ' @ every 1 day',
            ],
            ScheduledTicks::describe($schedule),
        );
    }

    #[Test]
    public function itPersistsItsCheckpointSoADailyPeriodCanElapse(): void
    {
        $schedule = (new AuditLogMaintenanceSchedule(new ArrayAdapter()))->getSchedule();

        // A non-stateful schedule recomputes its first run date from process boot, and `messenger:consume`
        // is replaced every hour by `--time-limit=3600`, so a daily period never comes due.
        $this->assertInstanceOf(\Symfony\Contracts\Cache\CacheInterface::class, $schedule->getState());
    }
}
