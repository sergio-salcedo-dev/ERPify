<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Closure;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Override;

/**
 * The real {@see RecoverySecretRepository}, with a hook at the statement that takes the
 * `identity_recovery_secret` row lock by selector.
 *
 * A lock ORDER is only observable from a contender, and a second transaction cannot be made to race this one
 * inside a single PHPUnit process — the image carries neither `pcntl` (no fork) nor the procedural `pgsql`
 * extension (no asynchronous query). What IS observable, and is the same claim stated without concurrency, is
 * the state of the other table at the instant this one is taken: "the redemption locks the user before the
 * secret" is exactly "when it takes the secret, it already holds the user".
 *
 * There are TWO hooks because one cannot answer both questions. The `before` hook sees precisely what the
 * transaction held on ARRIVAL, which is what makes "the user was already locked" and "the secret was not yet"
 * askable; it is structurally blind to whether the inner call locks anything, since it runs first. The
 * `after` hook is what sees that — an adapter that stopped locking leaves the row free at a point where this
 * one requires it held.
 *
 * It wraps the production adapter rather than replacing it, so what the probe observes is the lock the real
 * `SELECT … FOR UPDATE` takes, never one the test issued for itself.
 *
 * @internal
 */
final readonly class ProbingRecoverySecretRepository implements RecoverySecretRepository
{
    /**
     * @param Closure(): void $beforeSelectorLock runs immediately before the locked read by selector
     * @param Closure(): void $afterSelectorLock  runs immediately after it, while the lock it took is held
     */
    public function __construct(
        private RecoverySecretRepository $inner,
        private Closure $beforeSelectorLock,
        private Closure $afterSelectorLock,
    ) {
    }

    #[Override]
    public function save(RecoverySecret $secret): void
    {
        $this->inner->save($secret);
    }

    #[Override]
    public function remove(RecoverySecret $secret): void
    {
        $this->inner->remove($secret);
    }

    #[Override]
    public function findBySelector(string $selector): ?RecoverySecret
    {
        return $this->inner->findBySelector($selector);
    }

    #[Override]
    public function findBySelectorForUpdate(string $selector): ?RecoverySecret
    {
        ($this->beforeSelectorLock)();

        $secret = $this->inner->findBySelectorForUpdate($selector);

        ($this->afterSelectorLock)();

        return $secret;
    }

    #[Override]
    public function findByUserId(string $userId): ?RecoverySecret
    {
        return $this->inner->findByUserId($userId);
    }

    #[Override]
    public function findByUserIdForUpdate(string $userId): ?RecoverySecret
    {
        return $this->inner->findByUserIdForUpdate($userId);
    }

    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        return $this->inner->deleteAllForUser($userId);
    }
}
