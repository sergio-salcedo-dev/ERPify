<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session\Fixtures;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Override;

/**
 * The real store, with a rival forced into the one window a single PHP process can reach: immediately before a
 * single-row locked read, after the caller's unlocked read has already seen the row `ACTIVE` and its transaction
 * has begun. The hook runs a rival's committed write from another connection; the locked read that follows is the
 * real one, so what it answers is Postgres's re-check, not this double's.
 *
 * @internal
 */
final readonly class RivalBeforeLockSessionRepository implements SessionRepository
{
    public function __construct(
        private SessionRepository $inner,
        private Closure $rival,
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
    public function lockActiveById(SessionId $id): ?Session
    {
        ($this->rival)();

        return $this->inner->lockActiveById($id);
    }

    #[Override]
    public function findByUserId(string $userId): array
    {
        return $this->inner->findByUserId($userId);
    }

    #[Override]
    public function lockActiveForUser(string $userId): array
    {
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
}
