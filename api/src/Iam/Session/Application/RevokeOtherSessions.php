<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

use Erpify\Iam\Session\Domain\Event\OtherSessionsRevoked;
use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * "Sign out my other devices": bulk-revokes every active session of the user EXCEPT the one in hand, so the
 * current session is never self-expelled. The revocation is a directed UPDATE (no per-session aggregate
 * hydration), so a single coarse {@see OtherSessionsRevoked} — carrying the kept (current) session id so a
 * consumer never mistakes it for a full revoke — is emitted rather than one event per row. Both commit in one
 * transaction.
 *
 * **The session in hand must still be alive when the decision is taken, and that is checked under the lock,
 * not at admission.** The gate admitted the request when it arrived; between that read and this UPDATE another
 * session of the same identity may have evicted this one — the owner signing out an intruder, or a recovery
 * redemption doing it for them ({@see EvictOtherSessions}). Acting on the admission would let a session that
 * has already been evicted revoke the one that evicted it, which turns "sign out my other devices" into a race
 * a stolen session wins by firing continuously. So the user's active set is locked first, in the order every
 * locker of it uses, and a caller whose row is no longer in it is refused with the gate's own 401 and revokes
 * nothing.
 */
final readonly class RevokeOtherSessions
{
    public function __construct(
        private SessionRepository $sessions,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @throws SessionNoLongerActive when the session in hand was revoked before the lock was granted (401)
     */
    public function revoke(string $userId, SessionId $currentSessionId): void
    {
        Uuid::ensure($userId);

        $this->transactionManager->transactional(function () use ($userId, $currentSessionId): void {
            if (!$currentSessionId->isAmong($this->sessions->lockActiveForUser($userId))) {
                throw SessionNoLongerActive::forRequest();
            }

            $this->sessions->revokeOthersForUser($userId, $currentSessionId);
            $this->eventBus->publish(
                new OtherSessionsRevoked($userId, $currentSessionId->toString(), occurredOn: $this->clock->now()),
            );
        });
    }
}
