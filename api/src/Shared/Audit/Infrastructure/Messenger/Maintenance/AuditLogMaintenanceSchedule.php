<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance;

use Override;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Audit-owned maintenance schedule: a daily differentiated-retention prune of `audit_log` plus a daily
 * GDPR subject-erasure integrity reconciliation. It is a separate schedule from the event backbone's
 * `maintenance` one (Symfony forbids two providers per schedule name, and audit maintenance is the Audit
 * capability's concern, not event-dedup's), so it gets its own `scheduler_audit_maintenance` transport —
 * wired into the `scheduler_worker` (prod) and folded into the `messenger_worker` (dev) `messenger:consume`
 * commands.
 *
 * **`stateful()` is what makes a period longer than the worker's life mean anything.** Without a persisted
 * checkpoint the first run date is computed from the moment the process booted, and `messenger:consume`
 * runs under `--time-limit=3600` with `restart: unless-stopped` — so a `1 day` period is re-seeded every
 * hour and never comes due. The `app` pool backs it, and its store survives the restart in both
 * deployments; a deploy resets it, which costs one period of delay and nothing else.
 */
#[AsSchedule('audit_maintenance')]
final readonly class AuditLogMaintenanceSchedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $checkpointState)
    {
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->checkpointState)
            ->add(RecurringMessage::every('1 day', new PruneAuditLogMessage()))
            ->add(RecurringMessage::every('1 day', new ReconcileSubjectErasuresMessage()))
        ;
    }
}
