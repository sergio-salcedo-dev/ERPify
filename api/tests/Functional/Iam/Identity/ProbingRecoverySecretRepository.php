<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Closure;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Override;

/**
 * The real {@see RecoverySecretRepository}, with a hook at one of the two statements that take an
 * `identity_recovery_secret` row lock — by selector, which redemption resolves through, or by user id, which
 * minting and revocation reach the row by.
 *
 * A lock ORDER is only observable from a contender, and a second transaction cannot be made to race this one
 * inside a single PHPUnit process — the image carries neither `pcntl` (no fork) nor the procedural `pgsql`
 * extension (no asynchronous query). What IS observable, and is the same claim stated without concurrency, is
 * the state of the other table at the instant this one is taken: "the flow locks the user before the secret"
 * is exactly "when it takes the secret, it already holds the user".
 *
 * There are TWO hooks per statement because one cannot answer both questions. The `before` hook sees precisely
 * what the transaction held on ARRIVAL, which is what makes "the user was already locked" and "the secret was
 * not yet" askable; it is structurally blind to whether the inner call locks anything, since it runs first.
 * The `after` hook is what sees that — an adapter that stopped locking leaves the row free at a point where
 * this one requires it held.
 *
 * One instance wraps ONE statement, named at its factory. A probe that fired on both would report a flow
 * reaching the table twice as reaching it once, and could not tell which acquisition a reading belonged to.
 *
 * It wraps the production adapter rather than replacing it, so what the probe observes is the lock the real
 * `SELECT … FOR UPDATE` takes, never one the test issued for itself.
 *
 * @internal
 */
final readonly class ProbingRecoverySecretRepository implements RecoverySecretRepository
{
    private function __construct(
        private RecoverySecretRepository $inner,
        private ?Closure $beforeSelectorLock,
        private ?Closure $afterSelectorLock,
        private ?Closure $beforeUserIdLock,
        private ?Closure $afterUserIdLock,
    ) {
    }

    /**
     * @param Closure(): void $before runs immediately before the locked read by selector
     * @param Closure(): void $after  runs immediately after it, while the lock it took is held
     */
    public static function aroundSelectorLock(
        RecoverySecretRepository $inner,
        Closure $before,
        Closure $after,
    ): self {
        return new self($inner, $before, $after, null, null);
    }

    /**
     * @param Closure(): void $before runs immediately before the locked read by user id
     * @param Closure(): void $after  runs immediately after it, while the lock it took is held
     */
    public static function aroundUserIdLock(
        RecoverySecretRepository $inner,
        Closure $before,
        Closure $after,
    ): self {
        return new self($inner, null, null, $before, $after);
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
        $this->fire($this->beforeSelectorLock);

        $secret = $this->inner->findBySelectorForUpdate($selector);

        $this->fire($this->afterSelectorLock);

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
        $this->fire($this->beforeUserIdLock);

        $secret = $this->inner->findByUserIdForUpdate($userId);

        $this->fire($this->afterUserIdLock);

        return $secret;
    }

    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        return $this->inner->deleteAllForUser($userId);
    }

    private function fire(?Closure $hook): void
    {
        if ($hook instanceof Closure) {
            $hook();
        }
    }
}
