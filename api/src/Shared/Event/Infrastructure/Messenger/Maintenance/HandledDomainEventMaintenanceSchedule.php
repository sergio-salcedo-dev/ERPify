<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

use Override;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Maintenance schedule. The messenger_worker consumes the generated ticks via the
 * `scheduler_maintenance` transport (added to its `messenger:consume` command in the compose files):
 * a daily prune of stale dedup claims, and an hourly dead-letter backlog check that alarms over
 * threshold.
 *
 * **`stateful()` is what makes these periods mean anything.** Without a persisted checkpoint the next run
 * date is computed from the moment the process booted, and `messenger:consume` runs under
 * `--time-limit=3600` with `restart: unless-stopped` — so the daily prune is re-seeded every hour and never
 * comes due, and the hourly backlog check sits exactly on the boundary, racing the process that would emit
 * it. The `app` pool backs it, and its store survives the restart in both deployments.
 */
#[AsSchedule('maintenance')]
final readonly class HandledDomainEventMaintenanceSchedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $checkpointState)
    {
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->checkpointState)
            ->add(RecurringMessage::every('1 day', new PruneHandledDomainEventsMessage()))
            ->add(RecurringMessage::every('1 hour', new ReportDeadLetterBacklogMessage()))
        ;
    }
}
