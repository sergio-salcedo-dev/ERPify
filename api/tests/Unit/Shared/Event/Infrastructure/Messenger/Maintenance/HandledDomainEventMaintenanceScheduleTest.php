<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\HandledDomainEventMaintenanceSchedule;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\PruneFailedMessagesMessage;
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
    private const int HOURS_PER_DAY = 24;

    /**
     * How many alarm windows must fit inside the retention window. Ten is not a magic ratio, it is a floor
     * chosen so the assertion cannot be satisfied by nudging either constant a little: today's pair clears
     * it by a factor of three.
     */
    private const int MIN_ALARM_MARGIN = 10;

    #[Test]
    public function itSchedulesTheDailyPruneAndTheHourlyDeadLetterCheck(): void
    {
        $schedule = (new HandledDomainEventMaintenanceSchedule(new ArrayAdapter()))->getSchedule();

        // The two periods differ here, which is exactly what a count assertion cannot see: swapping them
        // would leave the prune running hourly and the backlog alarm daily, both green.
        $this->assertSame(
            [
                PruneFailedMessagesMessage::class . ' @ every 1 day',
                PruneHandledDomainEventsMessage::class . ' @ every 1 day',
                ReportDeadLetterBacklogMessage::class . ' @ every 1 hour',
            ],
            ScheduledTicks::describe($schedule),
        );
    }

    #[Test]
    public function theFailedPruneWindowStaysFarWiderThanTheBacklogAlarmItSharesATableWith(): void
    {
        $retentionHours = (new PruneFailedMessagesMessage())->retentionDays * self::HOURS_PER_DAY;
        $alarmAgeHours = (new ReportDeadLetterBacklogMessage())->maxAgeHours;

        // Both constants govern the same rows, and only one of them deletes. The alarm reports the age of
        // the OLDEST surviving failed message, so a retention window at or below its threshold would put a
        // ceiling on that age: the queue would go quiet because the pruner removed the evidence, not
        // because the backlog cleared. Tuning either constant toward the other has to go red here.
        $this->assertGreaterThan(
            $alarmAgeHours * self::MIN_ALARM_MARGIN,
            $retentionHours,
            'the prune must not reach rows the dead-letter alarm has not had ample time to raise',
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
