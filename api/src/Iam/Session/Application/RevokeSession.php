<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Revokes one session (a user logging out the current device, or the native-deauth reactor keeping the registry
 * in step with a credential change). Idempotent: a session that is already revoked or expired resolves to `null`
 * and the call is a no-op — revoking an inert session succeeds silently rather than raising, so both the HTTP
 * "log out" path and the fire-and-forget reactor are safe against a double-revoke race.
 */
final readonly class RevokeSession
{
    public function __construct(
        private SessionRepository $sessions,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
    ) {
    }

    public function revoke(SessionId $id): void
    {
        $session = $this->sessions->findActiveById($id);

        if (!$session instanceof Session) {
            return;
        }

        $session->revoke();

        $this->transactionManager->transactional(function () use ($session): void {
            $this->sessions->save($session);
            $this->eventBus->publish(...$session->pullDomainEvents());
        });
    }
}
