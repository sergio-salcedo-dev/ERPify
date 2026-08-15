<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Identity-owned maintenance schedule, carrying four recurring jobs: a reconciliation of the places that hold
 * a person's id against the identities still alive, the sweep that tells the owner of a locked identity that
 * it is locked, an inspection of the identity rows the authentication path reads without complaint, and the
 * retention sweep over the session registry. It gets its own `scheduler_identity_maintenance` transport —
 * wired into the `scheduler_worker` (prod) and folded into the `messenger_worker` (dev) `messenger:consume`
 * commands.
 *
 * Each tick joins this schedule rather than minting one of its own, and the boundary argument below is what
 * decides it: a separate provider per job would buy a transport to wire, a pairing for the compose gate to
 * check and a way to ship dead — each multiplied by four, for no isolation anyone needs. They do not share a
 * table: the reconciliation aggregates four sources across three contexts, two of the others read
 * `identity_user`, and the prune touches `iam_session` alone.
 *
 * A schedule of its own rather than more messages on the audit one, and the reason is a boundary rather
 * than the framework's one-provider-per-name rule: three of these controls are `Iam/Identity`'s — it is the
 * context that can say whether an id still names a live person, the one that owns the lockout, and the one
 * that knows what a readable identity row looks like — and hanging them off `Shared/Audit`'s schedule would
 * make a shared capability the owner of an identity concern.
 *
 * **The session prune is the exception to that ownership argument, and it is argued rather than assumed.**
 * The sweep belongs to `Iam/Session`, so on ownership alone it would earn a provider of its own. What decides
 * against one is cost measured against isolation nobody needs: a second provider mints a
 * `scheduler_session_maintenance` transport, which then has to be named in the `messenger:consume` command of
 * BOTH root compose files and paired to the right service in each, or it compiles, registers and ships dead
 * with every gate green. That is four edits and a new way to fail silently, bought for one daily tick whose
 * only collaborator is a published use case this context already consumes three times over. Joining here
 * mints no transport at all. The cost of joining is that no gate sees the tick — `php.lint.schedule-consumption`
 * is satisfied by the transport that already exists — so its unit test's assertion is the only thing that
 * fails when the tick is dropped, and that is where the guarantee lives.
 *
 * **The periods are set by what each check observes, not by symmetry.** The reconciliation looks for a
 * missed erasure, which is durable rather than transient: a shorter period would re-ask a question whose
 * answer only changes when an erasure runs, and the repair is an operator action that does not happen within
 * the hour anyway. The stored-identity inspection observes the same kind of state — a role value outside the
 * enum or a credential the authentication path cannot read stays wrong until someone repairs the row — so it
 * shares that cadence. The lockout sweep observes a state that lives fifteen minutes
 * ({@see \Erpify\Iam\Identity\Domain\Entity\User::LOCK_DURATION}), so a daily tick would see a given
 * lockout only by coincidence and would usually report nothing about an attack that had already run its
 * course. The session prune is daily for a third reason again — not what it observes but what it removes: its
 * windows are 30 and 90 days, so the finest cadence that changes any outcome is a day, and anything shorter
 * would re-run a DELETE that matched nothing.
 *
 * A missed tick is caught up rather than dropped (the generator yields one message per elapsed period), so an
 * outage is followed by a burst of sweeps. That is harmless for each of them and deliberately not
 * special-cased, though for different reasons: the lockout sweep carries no payload and its suppression stamp
 * is persisted, so replaying it produces candidate queries and no additional mail, while the reconciliation
 * and the stored-identity inspection are read-only and idempotent — a replay repeats their queries and, if
 * the finding still stands, its log line. The prune is the one that writes, and it is idempotent in the
 * stronger sense: its second run at the same instant matches nothing, because the first already deleted
 * everything the thresholds select.
 *
 * **`stateful()` is what makes "daily" true, and without it the period is a claim the deployment cannot
 * keep.** A schedule with no persisted state builds its checkpoint in process memory, so the first run date
 * is computed from the moment the worker booted — `now + 1 day`. Both workers run `messenger:consume`
 * under `--time-limit=3600` with `restart: unless-stopped`, so the process is replaced every hour and the
 * clock restarts with it: the next tick is permanently ~23 hours beyond the life of the process meant to
 * emit it, and a daily schedule never fires at all while every gate stays green.
 *
 * **Surviving the restart is not enough; it has to survive the DEPLOY, and that is why the pool is named
 * rather than autowired.** The `app` pool is the filesystem adapter, and in production its backing store is
 * the container's own writable layer — no service mounts a volume over `var/cache` there. A deploy therefore
 * recreates the container with an empty checkpoint, `Checkpoint::acquire()` seeds it to `now`, and the period
 * is anchored at that instant. The delay is not one period paid once: at a deploy cadence of one period or
 * less the tick is re-anchored before it ever comes due and never fires.
 * `cache.scheduler_checkpoint` is Postgres-backed and outlives the container; the measurement is in
 * `cache.yaml`. `NotifyLockedIdentitiesMessage` is the one member of this schedule the defect spares, its
 * five-minute period being far shorter than any deploy.
 */
#[AsSchedule('identity_maintenance')]
final readonly class IdentityMaintenanceSchedule implements ScheduleProviderInterface
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
            ->add(RecurringMessage::every('1 day', new ReconcilePersonReferencesMessage()))
            ->add(RecurringMessage::every('5 minutes', new NotifyLockedIdentitiesMessage()))
            ->add(RecurringMessage::every('1 day', new InspectStoredIdentityMessage()))
            ->add(RecurringMessage::every('1 day', new PruneRetiredSessionsMessage()))
        ;
    }
}
