<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

use Erpify\Iam\Session\Domain\Event\AllSessionsRevoked;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * "Sign out my other devices": bulk-revokes every active session of the user EXCEPT the one in hand, so the
 * current session is never self-expelled. The revocation is a directed UPDATE (no per-session aggregate
 * hydration), so a single coarse {@see AllSessionsRevoked} is emitted rather than one event per row. Both
 * commit in one transaction.
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

    public function revoke(string $userId, SessionId $currentSessionId): void
    {
        Uuid::ensure($userId);

        $this->transactionManager->transactional(function () use ($userId, $currentSessionId): void {
            $this->sessions->revokeOthersForUser($userId, $currentSessionId);
            $this->eventBus->publish(new AllSessionsRevoked($userId, occurredOn: $this->clock->now()));
        });
    }
}
