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
     * Records a failed password attempt for the identity resolved BY EMAIL. An unknown or malformed email is a
     * no-op — no row, no write, no event, no transaction — so a failure against a non-existent account leaves
     * nothing that could tell it apart from a real one (pre-identity indistinguishability); the ephemeral
     * per-IP throttle covers the anonymous flood.
     *
     * **Everything after that existence probe happens under `SELECT … FOR UPDATE`, and the probe itself is
     * allowed to decide nothing else.** The counter is the only state in this application written from a path
     * that holds just an address, and reading it unlocked let the increment be computed against a row another
     * transaction had already replaced: a recovery-secret redemption clears the lock and this write puts
     * `locked_until` straight back, which is precisely the state the redemption exists to leave. An
     * administrative unlock is the same shape. The window is the span from the read to the save, and an
     * attacker sustaining the attack that caused the lock is retrying continuously inside it.
     *
     * **The fast path this costs was never sound, which is why it goes rather than being preserved.** It
     * skipped the transaction when the aggregate reported the attempt changed nothing — a non-`ACTIVE` or
     * already-locked identity — but that report came from the same unlocked snapshot, so it was wrong in both
     * directions exactly when it mattered. What replaces it is a skip taken INSIDE the transaction: the
     * aggregate still refuses, and the write and the events are still skipped; only the BEGIN/COMMIT pair is
     * paid. That pair is bounded by an attempt which has already run a credential verification, so it is not
     * the round trip that decides the cost of a sustained attack.
     *
     * The provisional read deliberately does NOT call {@see User::recordFailedAttempt()}. Beyond deciding
     * nothing, that call also RECORDS on the aggregate, and the locked re-read re-hydrates mapped fields
     * without touching the recorded-event list — so a provisional call would leave a `UserLocked` behind to be
     * published a second time beside the authoritative one.
     */
    public function recordFailure(#[SensitiveParameter] string $email): void
    {
        try {
            $canonicalEmail = Email::from($email);
        } catch (InvalidEmail) {
            return;
        }

        // Existence only. It is the one thing safe to conclude from an unlocked row — a row that exists does
        // not stop existing under an attack — and it is what keeps an unknown address free of a transaction.
        if (!$this->users->findByEmail($canonicalEmail) instanceof User) {
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
     * Resolves the identity again under the row lock and lets the aggregate decide there, returning whatever
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

            // Gone between the probe and the lock — a hard-deleted identity, which the GDPR erasure does.
            // Nothing to count against a row that no longer exists.
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
