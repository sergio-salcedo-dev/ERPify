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
    public function itSchedulesASingleRecurringAuditLogPrune(): void
    {
        $schedule = (new AuditLogMaintenanceSchedule())->getSchedule();

        $this->assertCount(1, $schedule->getRecurringMessages());
    }
}
