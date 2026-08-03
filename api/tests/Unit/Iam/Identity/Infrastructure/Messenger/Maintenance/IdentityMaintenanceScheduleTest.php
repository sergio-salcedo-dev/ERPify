<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\IdentityMaintenanceSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(IdentityMaintenanceSchedule::class)]
final class IdentityMaintenanceScheduleTest extends TestCase
{
    #[Test]
    public function itSchedulesTheDailyPersonReferenceReconciliation(): void
    {
        $schedule = (new IdentityMaintenanceSchedule())->getSchedule();

        $this->assertCount(1, $schedule->getRecurringMessages());
    }
}
