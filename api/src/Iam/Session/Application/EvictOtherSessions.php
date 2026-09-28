<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

use Erpify\Iam\Session\Domain\Event\AllSessionsRevoked;
use Erpify\Iam\Session\Domain\Event\OtherSessionsRevoked;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Revokes every active session of the user except `$survivor`, on an authority that is NOT a session, and
 * reports whether `$survivor` is still alive. The published seam a recovery redemption consumes: the proof it
 * acts on is a verified credential held under lock, so a caller whose own session has already been revoked is
 * still entitled to evict the rest — which is exactly what separates it from {@see RevokeOtherSessions}, whose
 * authority IS the session in hand and which therefore refuses when that session is gone.
 *
 * **It opens no transaction of its own, and it must run inside the caller's.** The row locks it takes are
 * held until that transaction ends, and the eviction has to commit or roll back with whatever the caller
 * decided under the same locks — a redemption's consumption of its secret. Outside a transaction the locked
 * read is refused by the ORM rather than silently degrading to an unlocked one. {@see PurgeUserSessions}
 * joins its caller's unit of work on the same terms.
 *
 * The set is locked through {@see SessionRepository::lockActiveForUser()}, ascending by id, which is how every
 * bulk writer of a user's sessions acquires them, so a concurrent "sign out my other devices" from a session
 * this call is about to revoke either commits first — and this call then finds `$survivor` among the revoked
 * and says so — or waits, wakes to find its own session revoked, and is refused. Neither order lets the two
 * revoke each other's session and both commit.
 */
final readonly class EvictOtherSessions
{
    public function __construct(
        private SessionRepository $sessions,
        private EventBus $eventBus,
        private Clock $clock,
    ) {
    }

    /**
     * @return bool whether `$survivor` was still active when the set was locked; the others are revoked
     *              either way
     */
    public function evict(string $userId, SessionId $survivor): bool
    {
        Uuid::ensure($userId);

        $survived = $survivor->isAmong($this->sessions->lockActiveForUser($userId));

        $this->sessions->revokeOthersForUser($userId, $survivor);

        // The fact names what the store now holds. With the survivor already revoked nothing was kept, and an
        // `OtherSessionsRevoked` naming it as kept would be a permanent record of a session spared that was
        // not — the one field a consumer of that event is told to honour.
        $this->eventBus->publish(
            $survived
                ? new OtherSessionsRevoked($userId, $survivor->toString(), occurredOn: $this->clock->now())
                : new AllSessionsRevoked($userId, occurredOn: $this->clock->now()),
        );

        return $survived;
    }
}
