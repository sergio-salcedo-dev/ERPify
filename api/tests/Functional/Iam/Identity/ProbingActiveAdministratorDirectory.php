<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Closure;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Override;

/**
 * The real {@see ActiveAdministratorDirectory}, with a hook in the window between its two administrator
 * questions — after the unlocked reading has answered, before the locked one is asked.
 *
 * That window is the whole of #655. In production the invitation purge spans it, and a purge is a `DELETE`
 * that can block arbitrarily long behind a concurrent accept or revoke, so it is not a narrow window at all.
 * A contender that commits inside it is not racing anything: the erasure holds no lock on the subject's row
 * yet, so the grant lands immediately and the sequence is deterministic.
 *
 * This is the seam the sibling {@see ProbingUserRepository} cannot reach. Its hook fires before the
 * `identity_user` row lock taken by the delete, which is AFTER the locked reading — so a contender driven
 * from there always loses, and a test built on it can only ever observe the lock being taken, never the
 * refusal being made.
 *
 * It wraps the production adapter rather than replacing it, so both answers are the real statements'.
 *
 * @internal
 */
final readonly class ProbingActiveAdministratorDirectory implements ActiveAdministratorDirectory
{
    /**
     * @param Closure(): void $betweenTheTwoReadings runs after the unlocked answer and before the locked one
     */
    public function __construct(
        private ActiveAdministratorDirectory $inner,
        private Closure $betweenTheTwoReadings,
    ) {
    }

    #[Override]
    public function lockActiveAdministrators(): void
    {
        $this->inner->lockActiveAdministrators();
    }

    #[Override]
    public function survivesRemovalOf(string $userId): bool
    {
        return $this->inner->survivesRemovalOf($userId);
    }

    #[Override]
    public function holdsAdministratorRole(string $userId): bool
    {
        $answer = $this->inner->holdsAdministratorRole($userId);

        // After the answer, never before: the window this stands in for opens once the unlocked reading has
        // already decided, and a contender committing ahead of it would be refused by the fast path instead.
        ($this->betweenTheTwoReadings)();

        return $answer;
    }

    #[Override]
    public function holdsAdministratorRoleForUpdate(string $userId): bool
    {
        return $this->inner->holdsAdministratorRoleForUpdate($userId);
    }
}
