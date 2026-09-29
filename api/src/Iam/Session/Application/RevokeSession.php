<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Revokes one session (a user logging out the current device, the native-deauth reactor keeping the registry in
 * step with a credential change, or a recovery redemption compensating for the session it established).
 * Idempotent: a session that is already revoked, expired or absent resolves to `null` and the call is a no-op —
 * revoking an inert session succeeds silently rather than raising.
 *
 * The read that decides this happens inside the transaction and under the row's lock
 * ({@see SessionRepository::lockActiveById()}), because it also decides whether a `SessionRevoked` is published.
 * Read before the transaction, two callers revoking the same row — a log-out racing the deauth reactor, or
 * either one racing a bulk revocation or an erasure — could each find it `ACTIVE`, and each would re-stamp
 * `revoked_at` and publish: a duplicate `SessionRevoked`, or after an erasure one naming the erased person.
 * Under the lock the second caller waits, then finds the row inadmissible and does nothing.
 *
 * The unlocked read ahead of the transaction is not the decision, only a short-circuit, and it is kept for what
 * it guards rather than for speed: it is the first statement the store sees, and the repository converts a store
 * outage there into the domain `SessionStoreUnavailable` (503) — where an outage met first by the transaction's
 * own `BEGIN` would surface as a raw DBAL failure the port never declares. It also leaves an already-inert
 * session costing no transaction at all. A `null` there is final — revocation is terminal and expiry absolute —
 * so returning on it is safe; an `ACTIVE` answer decides nothing, and only the locked read inside does.
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
        if (!$this->sessions->findActiveById($id) instanceof Session) {
            return;
        }

        $this->transactionManager->transactional(function () use ($id): void {
            $session = $this->sessions->lockActiveById($id);

            if (!$session instanceof Session) {
                return;
            }

            $session->revoke();

            $this->sessions->save($session);
            $this->eventBus->publish(...$session->pullDomainEvents());
        });
    }
}
