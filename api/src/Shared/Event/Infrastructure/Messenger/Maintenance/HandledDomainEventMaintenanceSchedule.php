<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

use Override;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Maintenance schedule. The messenger_worker consumes the generated ticks via the
 * `scheduler_maintenance` transport (added to its `messenger:consume` command in the compose files):
 * a daily prune of stale dedup claims, and an hourly dead-letter backlog check that alarms over
 * threshold.
 */
#[AsSchedule('maintenance')]
final class HandledDomainEventMaintenanceSchedule implements ScheduleProviderInterface
{
    #[Override]
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 day', new PruneHandledDomainEventsMessage()))
            ->add(RecurringMessage::every('1 hour', new ReportDeadLetterBacklogMessage()))
        ;
    }
}
