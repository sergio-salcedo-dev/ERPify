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
 * enforced here — the single point every status change funnels through, before the aggregate mutates. The
 * check is a synchronous precondition (a rejection must stop the write, which a post-hoc event could only
 * observe). Persist and publish commit in one transaction (through the framework-free {@see TransactionManager}
 * seam, keeping the ORM out of Application) so the aggregate, its event-store rows and the outbox land atomically.
 */
final readonly class ChangeUserStatus
{
    public function __construct(
        private UserRepository $users,
        private ActiveAdministratorDirectory $administrators,
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
        $user = $this->findAndGuard($userId);
        $user->suspend();

        return $this->commit($user);
    }

    /**
     * @throws LastActiveAdministratorProtected when this is the last active administrator (409)
     * @throws UserNotFound                     when the id resolves to no identity (404)
     */
    public function deactivate(string $userId): User
    {
        $user = $this->findAndGuard($userId);
        $user->deactivate();

        return $this->commit($user);
    }

    private function findAndGuard(string $userId): User
    {
        Uuid::ensure($userId);

        $user = $this->users->findById($userId) ?? throw UserNotFound::withId($userId);

        if (!$this->administrators->keepsAnActiveAdminWithout($userId)) {
            throw LastActiveAdministratorProtected::forUser($userId);
        }

        return $user;
    }

    private function commit(User $user): User
    {
        $this->transactionManager->transactional(function () use ($user): void {
            $this->users->save($user);
            $this->eventBus->publish(...$user->pullDomainEvents());
        });

        return $user;
    }
}
