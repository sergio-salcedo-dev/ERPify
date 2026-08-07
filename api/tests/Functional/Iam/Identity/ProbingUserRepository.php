<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Closure;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Override;

/**
 * The real {@see UserRepository}, with a hook at the one statement that takes the `identity_user` row lock.
 *
 * A lock ORDER is only observable from a contender, and a second transaction cannot be made to race this one
 * inside a single PHPUnit process — the image carries neither `pcntl` (no fork) nor the procedural `pgsql`
 * extension (no asynchronous query). What IS observable, and is the same claim stated without concurrency, is
 * the state of the other tables at the instant this one is taken: "A locks X before Y" is exactly "when A
 * takes Y, A already holds X". The hook is where a second connection asks.
 *
 * It wraps the production adapter rather than replacing it, so what the probe observes is the lock the real
 * `DELETE` takes — never a statement the test wrote for itself.
 *
 * @internal
 */
final readonly class ProbingUserRepository implements UserRepository
{
    /**
     * @param Closure(): void $beforeRowLock runs immediately before the delete, while the transaction holds
     *                                       whatever it acquired earlier and nothing it acquires later
     */
    public function __construct(
        private UserRepository $inner,
        private Closure $beforeRowLock,
    ) {
    }

    #[Override]
    public function save(User $user): void
    {
        $this->inner->save($user);
    }

    #[Override]
    public function remove(User $user): void
    {
        ($this->beforeRowLock)();

        $this->inner->remove($user);
    }

    #[Override]
    public function findById(string $id): ?User
    {
        return $this->inner->findById($id);
    }

    #[Override]
    public function findByIdForUpdate(string $id): ?User
    {
        return $this->inner->findByIdForUpdate($id);
    }

    #[Override]
    public function findByEmail(Email $email): ?User
    {
        return $this->inner->findByEmail($email);
    }
}
