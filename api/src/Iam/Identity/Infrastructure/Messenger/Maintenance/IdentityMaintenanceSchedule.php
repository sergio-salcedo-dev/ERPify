<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Override;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

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
 *
 * **`stateful()` is what makes "daily" true, and without it the period is a claim the deployment cannot
 * keep.** A schedule with no persisted state builds its checkpoint in process memory, so the first run date
 * is computed from the moment the worker booted — `now + 1 day`. Both workers run `messenger:consume`
 * under `--time-limit=3600` with `restart: unless-stopped`, so the process is replaced every hour and the
 * clock restarts with it: the next tick is permanently ~23 hours beyond the life of the process meant to
 * emit it, and a daily schedule never fires at all while every gate stays green. The cache pool is the
 * `app` one, whose backing store survives the restart in both deployments (a named volume over the dev
 * worker's `var/cache`, the container's own writable layer in prod). A deploy legitimately resets it — that
 * only delays the first tick of the new release by one period.
 */
#[AsSchedule('identity_maintenance')]
final readonly class IdentityMaintenanceSchedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $checkpointState)
    {
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->checkpointState)
            ->add(RecurringMessage::every('1 day', new ReconcilePersonReferencesMessage()))
        ;
    }
}
