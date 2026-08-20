<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Event\UserLocked;
use Erpify\Iam\Identity\Domain\Exception\InvalidEmail;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use SensitiveParameter;

/**
 * Drives the persisted per-identity lockout counter from the two login outcomes: a failed attempt increments
 * it (and trips the lock at the threshold), a successful login clears it. Both are the same aggregate policy,
 * so they live behind one use case rather than two anaemic ones.
 *
 * The mutation IS the write here (unlike {@see ChangeUserStatus}, whose cross-aggregate guard runs before the
 * transaction), so each operation runs its whole `recordFailedAttempt` / `clearLockout` inside
 * `transactional()` — the aggregate, its event-store rows and the outbox land atomically through the
 * framework-free {@see TransactionManager} seam.
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
     * no-op — no row, no write, no event — so a failure against a non-existent account leaves nothing that
     * could tell it apart from a real one (pre-identity indistinguishability); the ephemeral per-IP throttle
     * covers the anonymous flood. An attempt the aggregate ignores (a non-`ACTIVE` or already-locked resolved
     * identity) likewise opens NO transaction — the write is skipped when nothing changed, so a sustained
     * attack on a locked account costs no per-attempt round-trip. Mirrors {@see clear()}.
     */
    public function recordFailure(#[SensitiveParameter] string $email): void
    {
        try {
            $canonicalEmail = Email::from($email);
        } catch (InvalidEmail) {
            return;
        }

        $user = $this->users->findByEmail($canonicalEmail);

        if (!$user instanceof User) {
            return;
        }

        if (!$user->recordFailedAttempt($this->clock->now())) {
            return;
        }

        $this->commit($user);
    }

    /**
     * Clears the lockout for an already-authenticated identity. Idempotent: on the common successful login
     * there is nothing to clear, so it opens NO transaction at all — the aggregate reports it stayed unchanged
     * and the whole write is skipped, keeping the hot auth path free of a BEGIN/COMMIT round-trip (and of any
     * failure mode) when there is no counter to reset.
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

        $this->commit($user);
    }

    /**
     * The audit projection of a tripped lockout is deliberately raised AFTER the transaction, never from a
     * handler inside it: the lock is the security control and its observability may not be able to roll it
     * back. Placing it here also means it can only describe a lockout that actually committed — a failing
     * commit throws, and nothing below it runs.
     *
     * Events are pulled before the closure only so the same list can be read afterwards; publishing still
     * happens inside the transaction, so the aggregate, its `event_store` rows and the outbox stay atomic.
     */
    private function commit(User $user): void
    {
        $events = $user->pullDomainEvents();

        $this->transactionManager->transactional(function () use ($user, $events): void {
            $this->users->save($user);
            $this->eventBus->publish(...$events);
        });

        foreach ($events as $event) {
            if ($event instanceof UserLocked) {
                $this->lockoutAudit->record($event->aggregateId());
            }
        }
    }
}
