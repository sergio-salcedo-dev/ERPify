<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

use Erpify\Iam\Session\Domain\Event\AllSessionsRevoked;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Bulk-revokes every active session of a user — the "force re-login everywhere" capability the password-reset
 * flow consumes to invalidate all sessions after a credential change. A directed UPDATE plus a single
 * {@see AllSessionsRevoked}, committed together.
 */
final readonly class RevokeAllSessions
{
    public function __construct(
        private SessionRepository $sessions,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    public function revoke(string $userId): void
    {
        Uuid::ensure($userId);

        $this->transactionManager->transactional(function () use ($userId): void {
            $this->sessions->revokeAllForUser($userId);
            $this->eventBus->publish(new AllSessionsRevoked($userId, occurredOn: $this->clock->now()));
        });
    }
}
