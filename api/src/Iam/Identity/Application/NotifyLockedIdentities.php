<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use DateInterval;
use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\LockedIdentityDirectory;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Clock\Domain\Clock;

/**
 * Tells the owners of currently locked identities that their account is locked, once per day at most.
 *
 * **It runs from a maintenance tick and never from a request, and that placement is the security property.**
 * Doing this work on the failing login would make the tenth attempt against a resolved identity cost an SMTP
 * round trip while an unknown address returned immediately — a timing oracle against the pre-identity
 * indistinguishability invariant (ADR docs/adr/identity-invitation-lifecycle.md D10). Moving it off the
 * request closes that channel by construction, rather than by a latency property some later listener could
 * erode without anything going red.
 *
 * The notice is detective: it reports the lock and grants nothing. See {@see AccountLockedEmailSender} for
 * why its channel forbids it from carrying anything exercisable.
 *
 * The DELIVERY is projected onto the `security` audit surface by {@see RecordLockoutNoticeAuditBestEffort},
 * alongside but distinct from {@see RecordLockoutAuditBestEffort}'s row for the TRIP: that row answers "the
 * lock was set", this one answers "the owner was actually told" — a mailer outage or a suppressed retry
 * inside the window leaves the first true and the second silent, which is exactly the gap an operator
 * investigating a live lockout needs to see. Kept as its own collaborator rather than inlined here for the
 * same reason {@see RecordLockoutAuditBestEffort} is split from {@see LoginAttemptRegistrar}: the sweep's own
 * orchestration and its audit projection are separate concerns with separate failure shapes, and folding both
 * into one class pushed it past the object-coupling threshold.
 */
final readonly class NotifyLockedIdentities
{
    /**
     * How long one notice suppresses the next for the same identity. A sustained attack re-locks the account
     * every fifteen minutes, so the lock's own duration would be a mail every quarter hour at the attacker's
     * choosing — the budget is what stops the control becoming the amplifier.
     */
    private const string NOTICE_INTERVAL = 'P1D';

    public function __construct(
        private LockedIdentityDirectory $lockedIdentities,
        private UserRepository $users,
        private SendAccountLockedEmailBestEffort $sender,
        private Clock $clock,
        private RecordLockoutNoticeAuditBestEffort $noticeAudit,
    ) {
    }

    /**
     * **One `now` for the whole sweep**, read once and used for the candidate query, for the staleness cutoff
     * and for the stamp. Reading the clock per row would let a row be selected as locked against one instant
     * and stamped with another, so a sweep that straddled a suppression boundary would leave stamps that
     * disagree with the query that produced them.
     */
    public function notifyLockedOwners(): void
    {
        $now = $this->clock->now();
        $staleFrom = $now->sub(new DateInterval(self::NOTICE_INTERVAL));

        foreach ($this->lockedIdentities->findLockedAt($now) as $userId) {
            $this->notifyOwner($userId, $now, $staleFrom);
        }
    }

    /**
     * **A persistence fault ends the tick rather than being absorbed per row, and that is measured rather
     * than chosen.** Doctrine's `UnitOfWork::commit()` closes the EntityManager from its `finally` whenever
     * the commit did not succeed, so once one `save()` has failed every later `findById()` in the same tick
     * throws on a closed manager. A per-row `catch` would therefore not keep the sweep alive: it would log
     * "the sweep continues" once per remaining identity while the sweep was already dead — an operator
     * reading a stream of survivable warnings for an unsurvivable fault.
     *
     * Letting it leave is what makes it visible. The scheduler transport neither retries nor routes to
     * `failed`, so Messenger logs the throw at `critical` and Sentry captures it — the same asymmetry
     * {@see \Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\ReconcilePersonReferencesHandler}
     * takes, where a control that cannot run is an engineering fault rather than a domain outcome. Stopping
     * costs nothing: no unsent identity was stamped, so the next tick finds exactly the same candidates five
     * minutes later.
     *
     * The one failure that genuinely is per-row is the send, and it never reaches here — the mailer sits
     * behind {@see SendAccountLockedEmailBestEffort}, which swallows it and reports `false`.
     *
     * **Accepted residual, not fixed — tracked in issue #860, not only here.** A closed decision still needs
     * an open, searchable place a future reader finds *before* writing a second racing job, not a docblock
     * that only speaks to whoever already opened this file. This paragraph is the code-facing summary;
     * issue #860 is the record — reopen the decision there, not by editing this comment.
     *
     * A GDPR erasure racing this method can leave a post-erasure audit row naming the erased subject.
     * `User` carries no `#[ORM\Version]`, so if `FulfilIdentityErasure` deletes
     * this same identity between the `findById()` above and `save()` below, Doctrine's plain `UPDATE` affects
     * zero rows without raising — `save()` returns as if it succeeded — and {@see RecordLockoutNoticeAuditBestEffort}
     * then writes a fresh `audit_log` row carrying the just-erased subject's real id. Window is the span of
     * one `findById()`-to-`save()` pair (milliseconds), not the five-minute tick.
     *
     * Accepted rather than closed with a lock or a re-check, because the consequence this repo actually cares
     * about — a person's id surviving its own erasure **undetected** — does not follow from the race:
     * {@see \Erpify\Shared\Audit\Infrastructure\Persistence\DbalPersonResourceReferences} already scans
     * `audit_log.resource_id` where `resource_erased = FALSE`, and `identity:gdpr:reconcile-subject-references`
     * already cross-checks that set against live identities. A row this race produces resolves to no live
     * subject and surfaces as a reported divergence on the reconciler's next run — `erasure → race → stale
     * audit row → reconcile → divergence detected`, not `→ nobody knows it exists`. A method-scoped re-check
     * immediately before the audit write would still leave a (smaller) window unless it shared one
     * transaction and a compatible lock with the erasure path — at which point it stops being a narrow patch
     * and becomes exactly the aggregate-wide concurrency policy this residual does not yet justify (`User`
     * has one demonstrated stale-write race, not a pattern).
     *
     * **Detection predicate** (compatible-with, not proof-of, absent stronger correlation): a divergence over
     * `audit_log.resource_id` naming a `User` that no longer resolves to a live identity, reported by
     * `identity:gdpr:reconcile-subject-references` temporally close to a `notifyLockedOwners()` tick.
     *
     * **Reopen this decision if either:** (1) a second scheduled job implements the same
     * read → work → save → audit shape against `User` — two independent races sharing one root cause is the
     * trigger for a shared concurrency mechanism on the aggregate, one is not; or (2) the detective control
     * above actually surfaces a real instance of this race that cannot be resolved within its normal
     * operating cycle.
     */
    private function notifyOwner(string $userId, DateTimeImmutable $now, DateTimeImmutable $staleFrom): void
    {
        $user = $this->users->findById($userId);

        // Re-read rather than trusted from the query: an owner who signs in between the two clears the
        // lock, and the aggregate is the only thing that knows a cleared lock leaves the stamp standing.
        if (!$user instanceof User || !$user->awaitsLockoutNoticeAt($now, $staleFrom)) {
            return;
        }

        if (!$this->sender->send($user->email())) {
            return;
        }

        // Stamped only behind a send that reported success, so a mailer outage never buys a day of
        // silence about a live lockout. The converse — a delivery whose stamp then fails — re-sends on
        // the next tick, which is the at-least-once side this deliberately takes.
        $user->markLockoutNotified($now);

        $this->users->save($user);

        // Reported AFTER the stamp already committed, so a lost audit projection can never look like the
        // notification itself failed — the mail already sent, and the suppression window already stands.
        $this->noticeAudit->record($userId, $user->lockedUntil());
    }
}
