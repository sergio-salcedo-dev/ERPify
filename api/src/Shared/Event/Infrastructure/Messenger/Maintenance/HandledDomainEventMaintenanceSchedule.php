<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Maintenance schedule. The messenger_worker consumes the generated ticks via the
 * `scheduler_maintenance` transport (added to its `messenger:consume` command in the compose files):
 * two daily prunes — stale dedup claims, and the failure transport past its retention window — and an
 * hourly dead-letter backlog check that alarms over threshold.
 *
 * The prune and the alarm read the same table on purpose and must not be tuned independently: the alarm
 * reports the age of the oldest surviving failed message, so a retention window narrow enough to reach rows
 * it has not yet raised would cap that age and quiet the queue by deleting its own evidence. The margin
 * between the two windows is asserted in this schedule's test.
 *
 * **`stateful()` is what makes these periods mean anything.** Without a persisted checkpoint the next run
 * date is computed from the moment the process booted, and `messenger:consume` runs under
 * `--time-limit=3600` with `restart: unless-stopped` — so the daily prune is re-seeded every hour and never
 * comes due, and the hourly backlog check sits exactly on the boundary, racing the process that would emit
 * it.
 *
 * **The pool is named rather than autowired, and that is the load-bearing half.** The autowired `app` pool
 * is the filesystem adapter under `var/cache`, which no production service mounts a volume over, so a
 * deploy recreates the container with an empty checkpoint; `Checkpoint::acquire()` seeds it to `now` and
 * the period is anchored there, putting the first run a full day after every deploy. `cache.scheduler_checkpoint`
 * is Postgres-backed and outlives the container. The arithmetic and its measurement live in `cache.yaml`.
 */
#[AsSchedule('maintenance')]
final readonly class HandledDomainEventMaintenanceSchedule implements ScheduleProviderInterface
{
    public function __construct(
        #[Autowire(service: 'cache.scheduler_checkpoint')]
        private CacheInterface $checkpointState,
    ) {
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->checkpointState)
            ->add(RecurringMessage::every('1 day', new PruneHandledDomainEventsMessage()))
            ->add(RecurringMessage::every('1 day', new PruneFailedMessagesMessage()))
            ->add(RecurringMessage::every('1 hour', new ReportDeadLetterBacklogMessage()))
        ;
    }
}
