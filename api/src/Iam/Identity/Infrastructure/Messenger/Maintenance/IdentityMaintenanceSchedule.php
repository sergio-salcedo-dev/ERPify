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
 * Identity-owned maintenance schedule, carrying the three recurring checks this context owns: a
 * reconciliation of the places that hold a person's id against the identities still alive, the sweep that
 * tells the owner of a locked identity that it is locked, and an inspection of the identity rows the
 * authentication path reads without complaint. It gets its own `scheduler_identity_maintenance` transport —
 * wired into the `scheduler_worker` (prod) and folded into the `messenger_worker` (dev) `messenger:consume`
 * commands.
 *
 * Each tick joins this schedule rather than minting one of its own, and the boundary argument below is what
 * decides it: all three are controls this context owns, so a separate provider per check would buy a
 * transport to wire, a pairing for the compose gate to check and a way to ship dead — each multiplied by
 * three, for no isolation anyone needs. They do not share a table: the reconciliation aggregates four
 * sources across three contexts, while the other two read `identity_user`.
 *
 * A schedule of its own rather than more messages on the audit one, and the reason is a boundary rather
 * than the framework's one-provider-per-name rule: all three controls are `Iam/Identity`'s — it is the
 * context that can say whether an id still names a live person, the one that owns the lockout, and the one
 * that knows what a readable identity row looks like — and hanging them off `Shared/Audit`'s schedule would
 * make a shared capability the owner of an identity concern.
 *
 * **The periods are set by what each check observes, not by symmetry.** The reconciliation looks for a
 * missed erasure, which is durable rather than transient: a shorter period would re-ask a question whose
 * answer only changes when an erasure runs, and the repair is an operator action that does not happen within
 * the hour anyway. The stored-identity inspection observes the same kind of state — a role value outside the
 * enum or a credential the authentication path cannot read stays wrong until someone repairs the row — so it
 * shares that cadence. The lockout sweep observes a state that lives fifteen minutes
 * ({@see \Erpify\Iam\Identity\Domain\Entity\User::LOCK_DURATION}), so a daily tick would see a given
 * lockout only by coincidence and would usually report nothing about an attack that had already run its
 * course.
 *
 * A missed tick is caught up rather than dropped (the generator yields one message per elapsed period), so an
 * outage is followed by a burst of sweeps. That is harmless for each of them and deliberately not
 * special-cased, though for different reasons: the lockout sweep carries no payload and its suppression stamp
 * is persisted, so replaying it produces candidate queries and no additional mail, while the reconciliation
 * and the stored-identity inspection are read-only and idempotent — a replay repeats their queries and, if
 * the finding still stands, its log line.
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
            ->add(RecurringMessage::every('5 minutes', new NotifyLockedIdentitiesMessage()))
            ->add(RecurringMessage::every('1 day', new InspectStoredIdentityMessage()))
        ;
    }
}
