<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Override;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Identity-owned maintenance schedule: a daily reconciliation of the places that hold a person's id against
 * the identities still alive. It gets its own `scheduler_identity_maintenance` transport — wired into the
 * `scheduler_worker` (prod) and folded into the `messenger_worker` (dev) `messenger:consume` commands.
 *
 * A schedule of its own rather than a third message on the audit one, and the reason is a boundary rather
 * than the framework's one-provider-per-name rule: this control is `Iam/Identity`'s — it is the context that
 * can say whether an id still names a live person — and hanging it off `Shared/Audit`'s schedule would make a
 * shared capability the owner of an identity concern.
 *
 * Daily, matching its siblings. The divergence it looks for is a missed erasure, which is durable rather than
 * transient: a shorter period would re-ask a question whose answer only changes when an erasure runs, and the
 * repair is an operator action that does not happen within the hour anyway.
 */
#[AsSchedule('identity_maintenance')]
final class IdentityMaintenanceSchedule implements ScheduleProviderInterface
{
    #[Override]
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 day', new ReconcilePersonReferencesMessage()))
        ;
    }
}
