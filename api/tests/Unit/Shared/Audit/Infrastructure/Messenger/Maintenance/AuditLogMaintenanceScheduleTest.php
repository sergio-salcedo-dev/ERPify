<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance\AuditLogMaintenanceSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditLogMaintenanceSchedule::class)]
final class AuditLogMaintenanceScheduleTest extends TestCase
{
    #[Test]
    public function itSchedulesTheDailyRetentionPruneAndErasureReconciliation(): void
    {
        $schedule = (new AuditLogMaintenanceSchedule())->getSchedule();

        // Two daily maintenance ticks: the retention prune and the subject-erasure reconciliation.
        $this->assertCount(2, $schedule->getRecurringMessages());
    }
}
