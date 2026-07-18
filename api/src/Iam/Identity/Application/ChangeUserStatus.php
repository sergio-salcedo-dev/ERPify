<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\LastActiveAdministratorProtected;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Applies a post-active lifecycle transition (`suspend` / `deactivate`) to an identity.
 *
 * The "keep ≥1 active ADMIN" invariant is a cross-aggregate rule the {@see User} cannot know about, so it is
 * enforced here — the single point every status change funnels through. The guard runs inside the write
 * transaction and takes a row-level lock on the active-admin set ({@see ActiveAdministratorDirectory}), so two
 * concurrent last-two-admin transitions serialize instead of both passing a stale snapshot and draining every
 * administrator: the loser re-reads the committed state and is rejected before it mutates. Persist and publish
 * commit in that same transaction (through the framework-free {@see TransactionManager} seam, keeping the ORM
 * out of Application) so the aggregate, its event-store rows and the outbox land atomically.
 *
 * A successful transition then revokes the identity's live sessions ({@see RevokeSessionsBestEffort},
 * post-commit, best-effort). A pure status flip touches neither credential nor roles, so the native
 * de-authentication paths never fire; suspending is an access control that must cut live access at once, and
 * the fail-closed session gate only walls the suspended identity once its registry rows are gone. Swallowing a
 * revoke failure (logged for ops) mirrors {@see CompletePasswordReset}: the change already committed.
 */
final readonly class ChangeUserStatus
{
    public function __construct(
        private UserRepository $users,
        private ActiveAdministratorDirectory $administrators,
        private RevokeSessionsBestEffort $revokeSessions,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws LastActiveAdministratorProtected when this is the last active administrator (409)
     * @throws UserNotFound                     when the id resolves to no identity (404)
     */
    public function suspend(string $userId): User
    {
        return $this->transition($userId, static function (User $user): void {
            $user->suspend();
        });
    }

    /**
     * @throws LastActiveAdministratorProtected when this is the last active administrator (409)
     * @throws UserNotFound                     when the id resolves to no identity (404)
     */
    public function deactivate(string $userId): User
    {
        return $this->transition($userId, static function (User $user): void {
            $user->deactivate();
        });
    }

    /**
     * @param callable(User): void $applyTransition
     */
    private function transition(string $userId, callable $applyTransition): User
    {
        Uuid::ensure($userId);

        $user = $this->transactionManager->transactional(function () use ($userId, $applyTransition): User {
            $user = $this->users->findById($userId) ?? throw UserNotFound::withId($userId);

            if (!$this->administrators->keepsAnActiveAdminWithout($userId)) {
                throw LastActiveAdministratorProtected::forUser($userId);
            }

            $applyTransition($user);
            $this->users->save($user);
            $this->eventBus->publish(...$user->pullDomainEvents());

            return $user;
        });

        $this->revokeSessions->revoke($userId);

        return $user;
    }
}
