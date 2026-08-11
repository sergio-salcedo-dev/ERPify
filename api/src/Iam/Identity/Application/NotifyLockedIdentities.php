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
    }
}
