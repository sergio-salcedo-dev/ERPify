<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\HandledDomainEventMaintenanceSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(HandledDomainEventMaintenanceSchedule::class)]
final class HandledDomainEventMaintenanceScheduleTest extends TestCase
{
    #[Test]
    public function itSchedulesASingleRecurringMaintenanceMessage(): void
    {
        $schedule = (new HandledDomainEventMaintenanceSchedule())->getSchedule();

        $this->assertCount(1, $schedule->getRecurringMessages());
    }
}
