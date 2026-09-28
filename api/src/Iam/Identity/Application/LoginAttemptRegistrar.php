<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Event\UserLocked;
use Erpify\Iam\Identity\Domain\Exception\InvalidEmail;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use SensitiveParameter;

/**
 * Drives the persisted per-identity lockout counter from the two login outcomes: a failed attempt increments
 * it (and trips the lock at the threshold), a successful login clears it. Both are the same aggregate policy,
 * so they live behind one use case rather than two anaemic ones.
 *
 * The mutation IS the write here (unlike {@see ChangeUserStatus}, whose cross-aggregate guard runs before the
 * transaction), so each operation runs its `recordFailedAttempt` / `clearLockout` inside `transactional()` —
 * the aggregate, its event-store rows and the outbox land atomically through the framework-free
 * {@see TransactionManager} seam.
 *
 * **The two halves are NOT symmetric about the row lock, and that is a decision.** The failure path resolves
 * its identity under `SELECT … FOR UPDATE` and decides there, because it is the writer that can undo somebody
 * else's committed unlock — a redemption or an administrator clearing the lock, immediately followed by this
 * path restoring `locked_until` from a snapshot taken before it. The success path still decides on an
 * unlocked read, and its exposure is the mirror image: a lost update leaves a counter standing for an
 * identity that has just authenticated. That is a smaller and self-correcting fault — the next successful
 * login clears it, and a lapsed `lockedUntil` already reads as unlocked without any write — and closing it
 * would put a row lock on the hot authentication path for every login that has a counter to reset. It is
 * named here rather than left to be discovered.
 */
final readonly class LoginAttemptRegistrar
{
    public function __construct(
        private UserRepository $users,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
        private RecordLockoutAuditBestEffort $lockoutAudit,
    ) {
    }

    /**
     * Records a failed password attempt for the identity resolved BY EMAIL. A malformed email is a no-op — it
     * cannot name an account, so skipping it tells a caller nothing they did not send. Every well-formed
     * address, known or not, takes the SAME transaction and the SAME locked read; only what that read returns
     * decides whether anything is written.
     *
     * **The transaction is paid for an unknown address on purpose, and that is the whole point of its shape.**
     * A branch that skipped it for an unknown address would make BEGIN + `SELECT … FOR UPDATE` + COMMIT a clean
     * existence signal — a failed login for an unknown address answers faster at p50 than one for an existing `ACTIVE`
     * identity, separably above chance (PRODUCTION_SECURITY_CHECKLIST.md §7) — so both branches take the same round
     * trips. What an unknown address still does not pay is the hydration of a row, the wait on a row lock another
     * attempt against the same address holds, and, for an `ACTIVE` identity, the counter's UPDATE, because there is no
     * row — that is the residual, and it is not claimed to be unclassifiable. The price is a transaction per failed
     * login against an address that does not exist, bounded by the login throttle and by a credential verification the
     * attempt has already paid; the locking read over no row locks nothing, so it contends with nobody.
     *
     * **Everything is decided under `SELECT … FOR UPDATE`.** The counter is the only state in this application
     * written from a path that holds just an address, and deciding it on an unlocked read would let the increment be
     * computed against a row another transaction had already replaced: a recovery-secret redemption clears the
     * lock and this write puts `locked_until` straight back, which is precisely the state the redemption exists
     * to leave. An administrative unlock is the same shape. A non-`ACTIVE` or already-locked identity is refused
     * INSIDE the transaction: the aggregate still refuses, the write and the events are still skipped, and only
     * the BEGIN/COMMIT pair is paid.
     */
    public function recordFailure(#[SensitiveParameter] string $email): void
    {
        try {
            $canonicalEmail = Email::from($email);
        } catch (InvalidEmail) {
            return;
        }

        $events = $this->commitUnderLock($canonicalEmail);

        foreach ($events as $event) {
            if ($event instanceof UserLocked) {
                $this->lockoutAudit->record($event->aggregateId());
            }
        }
    }

    /**
     * Clears the lockout for an already-authenticated identity. Idempotent: on the common successful login
     * there is nothing to clear, so it opens NO transaction at all — the aggregate reports it stayed unchanged
     * and the whole write is skipped, keeping the hot auth path free of a BEGIN/COMMIT round-trip (and of any
     * failure mode) when there is no counter to reset.
     *
     * That skip is decided on an UNLOCKED read, unlike its sibling above; the class docblock states why the
     * two differ and what the remaining exposure on this side is.
     */
    public function clear(string $userId): void
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            return;
        }

        if (!$user->clearLockout()) {
            return;
        }

        $events = $user->pullDomainEvents();

        $this->transactionManager->transactional(function () use ($user, $events): void {
            $this->users->save($user);
            $this->eventBus->publish(...$events);
        });
    }

    /**
     * Resolves the identity under the row lock and lets the aggregate decide there, returning whatever
     * it recorded so the caller can project it after the commit.
     *
     * The audit projection is deliberately raised by the CALLER, after this returns, never from inside: the
     * lock is the security control and its observability may not be able to roll it back. It also means the
     * projection can only describe a lockout that actually committed — a failing commit throws, and nothing
     * after it runs.
     *
     * Publishing stays INSIDE the transaction, so the aggregate, its `event_store` rows and the outbox land
     * atomically; the list is returned rather than pulled again afterwards because pulling twice would yield
     * an empty second read.
     *
     * @return list<DomainEvent>
     */
    private function commitUnderLock(#[SensitiveParameter] Email $email): array
    {
        return $this->transactionManager->transactional(function () use ($email): array {
            $user = $this->users->findByEmailForUpdate($email);

            // An unknown address, or an identity hard-deleted by the GDPR erasure: nothing to count. The
            // transaction and the locked read have already been paid, so this branch costs the same round
            // trips as a refused attempt against a row that exists.
            if (!$user instanceof User) {
                return [];
            }

            if (!$user->recordFailedAttempt($this->clock->now())) {
                return [];
            }

            $events = $user->pullDomainEvents();

            $this->users->save($user);
            $this->eventBus->publish(...$events);

            return $events;
        });
    }
}
