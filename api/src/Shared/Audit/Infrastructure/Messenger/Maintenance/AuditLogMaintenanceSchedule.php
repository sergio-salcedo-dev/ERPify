<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance;

use Override;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Audit-owned maintenance schedule: a daily differentiated-retention prune of `audit_log` plus a daily
 * GDPR subject-erasure integrity reconciliation. It is a separate schedule from the event backbone's
 * `maintenance` one (Symfony forbids two providers per schedule name, and audit maintenance is the Audit
 * capability's concern, not event-dedup's), so it gets its own `scheduler_audit_maintenance` transport —
 * wired into the `scheduler_worker` (prod) and folded into the `messenger_worker` (dev) `messenger:consume`
 * commands.
 */
#[AsSchedule('audit_maintenance')]
final class AuditLogMaintenanceSchedule implements ScheduleProviderInterface
{
    #[Override]
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 day', new PruneAuditLogMessage()))
            ->add(RecurringMessage::every('1 day', new ReconcileSubjectErasuresMessage()))
        ;
    }
}
