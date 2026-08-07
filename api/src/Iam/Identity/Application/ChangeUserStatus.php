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
 * That set lock protects the invariant, not the target: it covers the rows that are active administrators, so
 * it neither re-hydrates the aggregate already loaded in the identity map nor touches the target's row at all
 * when the target is not an active administrator. The aggregate is therefore read through
 * {@see UserRepository::findByIdForUpdate()}, which locks and refreshes it, so the state machine
 * ({@see User::suspend()} / {@see User::deactivate()}, both requiring `ACTIVE`) decides against committed state
 * rather than a snapshot a rival transaction has already superseded. Without it two concurrent transitions both
 * read `ACTIVE`, both pass their guard, and the loser writes its terminal state over the winner's — recording a
 * transition the state machine forbids, and appending its event to a log that cannot be rewritten.
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
            // Before the target row, never after: the guard below locks the active-admin set in `id` order,
            // and a target that is an ACTIVE administrator is a member of it. Locking the row first would hold
            // one member out of that order, so two concurrent transitions on administrators X and Y
            // (X.id < Y.id) would each hold one and wait for the other.
            $this->administrators->lockActiveAdministrators();

            $user = $this->users->findByIdForUpdate($userId) ?? throw UserNotFound::withId($userId);

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
