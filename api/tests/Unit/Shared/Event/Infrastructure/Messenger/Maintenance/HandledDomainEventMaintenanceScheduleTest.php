<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\HandledDomainEventMaintenanceSchedule;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\PruneHandledDomainEventsMessage;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\ReportDeadLetterBacklogMessage;
use Erpify\Tests\Support\ScheduledTicks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @internal
 */
#[CoversClass(HandledDomainEventMaintenanceSchedule::class)]
final class HandledDomainEventMaintenanceScheduleTest extends TestCase
{
    #[Test]
    public function itSchedulesTheDailyPruneAndTheHourlyDeadLetterCheck(): void
    {
        $schedule = (new HandledDomainEventMaintenanceSchedule(new ArrayAdapter()))->getSchedule();

        // The two periods differ here, which is exactly what a count assertion cannot see: swapping them
        // would leave the prune running hourly and the backlog alarm daily, both green.
        $this->assertSame(
            [
                PruneHandledDomainEventsMessage::class . ' @ every 1 day',
                ReportDeadLetterBacklogMessage::class . ' @ every 1 hour',
            ],
            ScheduledTicks::describe($schedule),
        );
    }

    #[Test]
    public function itPersistsItsCheckpointSoItsPeriodsCanElapse(): void
    {
        $schedule = (new HandledDomainEventMaintenanceSchedule(new ArrayAdapter()))->getSchedule();

        // Without state the daily prune never comes due under an hourly `--time-limit`, and the hourly
        // backlog check sits exactly on the boundary — racing the process that would emit it.
        $this->assertInstanceOf(\Symfony\Contracts\Cache\CacheInterface::class, $schedule->getState());
    }
}
