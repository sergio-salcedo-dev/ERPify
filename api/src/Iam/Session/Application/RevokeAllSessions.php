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
 * {@see AllSessionsRevoked}, committed together — and the event only when the UPDATE flipped a row, because
 * it states that sessions were revoked: an identity with none active records nothing, and the call is still
 * a success.
 *
 * The identity is held first, through {@see SessionSubjectLock}, as a login holds it: every caller revokes after
 * its own commit, so an erasure of the same person can land in between, and the event would then append the
 * erased person's id after the erasure rewrote the business log. Held first, the erasure waits for this to
 * commit and rewrites what it wrote; arriving second, this finds no identity and has nothing left to revoke.
 */
final readonly class RevokeAllSessions
{
    public function __construct(
        private SessionRepository $sessions,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
        private SessionSubjectLock $subjectLock,
    ) {
    }

    public function revoke(string $userId): void
    {
        Uuid::ensure($userId);

        $this->transactionManager->transactional(function () use ($userId): void {
            if (!$this->subjectLock->holdsLive($userId)) {
                return;
            }

            // Ordered first, so this UPDATE acquires the user's active rows in the id order the two "revoke the
            // others" paths lock them in, rather than in whatever order the scan meets them — which is the
            // cycle a suspension racing the suspended user's own "sign out my other devices" would close.
            $this->sessions->lockActiveForUser($userId);

            if (0 === $this->sessions->revokeAllForUser($userId)) {
                return;
            }

            $this->eventBus->publish(new AllSessionsRevoked($userId, occurredOn: $this->clock->now()));
        });
    }
}
