<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session\Fixtures;

use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Override;

/**
 * The real store, with one interleaving forced into it: the FIRST locked read of an identity's sessions is
 * preceded by revoking all of them, inside the same transaction. That is what a concurrent "sign out my other
 * devices" from another session looks like to a redemption that has just minted its own and not yet locked the
 * set — the redeemed session is gone when the eviction looks for it. Every later call is the real store
 * untouched, so the retry that follows meets the ordinary path.
 */
final class RevokingOnFirstLockSessionRepository implements SessionRepository
{
    private bool $interfered = false;

    public function __construct(
        private readonly SessionRepository $inner,
    ) {
    }

    #[Override]
    public function save(Session $session): void
    {
        $this->inner->save($session);
    }

    #[Override]
    public function findActiveById(SessionId $id): ?Session
    {
        return $this->inner->findActiveById($id);
    }

    #[Override]
    public function findByUserId(string $userId): array
    {
        return $this->inner->findByUserId($userId);
    }

    #[Override]
    public function lockActiveForUser(string $userId): array
    {
        if (!$this->interfered) {
            $this->interfered = true;
            $this->inner->revokeAllForUser($userId);
        }

        return $this->inner->lockActiveForUser($userId);
    }

    #[Override]
    public function revokeOthersForUser(string $userId, SessionId $currentSessionId): void
    {
        $this->inner->revokeOthersForUser($userId, $currentSessionId);
    }

    #[Override]
    public function revokeAllForUser(string $userId): void
    {
        $this->inner->revokeAllForUser($userId);
    }

    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        return $this->inner->deleteAllForUser($userId);
    }

    #[Override]
    public function deleteRetired(DateTimeImmutable $revokedBefore, DateTimeImmutable $expiredBefore): int
    {
        return $this->inner->deleteRetired($revokedBefore, $expiredBefore);
    }

    public function interfered(): bool
    {
        return $this->interfered;
    }
}
