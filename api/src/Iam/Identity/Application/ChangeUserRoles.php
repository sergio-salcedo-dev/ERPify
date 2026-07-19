<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\LastActiveAdministratorProtected;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Replaces an identity's role set — an assignment of the whole set, never a grant/revoke delta.
 *
 * Mirrors the pipeline of {@see ChangeUserStatus} (ensure the id, then read-guard-mutate-save-publish inside
 * one transaction, then tear sessions down post-commit) and diverges from it in exactly two places, both
 * because roles are not a lifecycle:
 *
 * 1. The "keep ≥1 active ADMIN" guard is CONDITIONAL. A status transition always pulls its subject out of the
 *    active-admin pool, so {@see ChangeUserStatus} always asks. A role change only threatens the invariant when
 *    it demotes an active administrator, so asking unconditionally would refuse a legitimate
 *    `[ADMIN] -> [ADMIN, EDITOR]` on the only administrator — who is not leaving the pool at all. When the guard
 *    does apply it reads through {@see ActiveAdministratorDirectory}, whose row lock is taken inside this
 *    transaction, so two concurrent demotions serialize instead of both passing a stale snapshot.
 * 2. Re-sending the set an identity already holds is a legitimate no-op, not a conflict: the role set is not
 *    unidirectional the way the identity lifecycle is. It short-circuits before mutating, so a redundant save
 *    neither writes, nor emits an event, nor — the reason this matters — tears down live sessions.
 *
 * The post-commit revoke is defence in depth here, not the only barrier. A changed role set already
 * de-authenticates natively (`SecurityUser` is not `EquatableInterface`, so the token comparison covers roles
 * and the next request of the affected identity drops its session), but that path is lazy and reaches only the
 * session making that request; revoking eagerly cuts every device at once. Swallowing a revoke failure mirrors
 * {@see CompletePasswordReset}: the role change already committed.
 */
final readonly class ChangeUserRoles
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
     * @throws LastActiveAdministratorProtected when demoting the last active administrator (409)
     * @throws UserNotFound                     when the id resolves to no identity (404)
     */
    public function run(string $userId, Role ...$roles): User
    {
        Uuid::ensure($userId);

        [$user, $replaced] = $this->transactionManager->transactional(
            function () use ($userId, $roles): array {
                $user = $this->users->findById($userId) ?? throw UserNotFound::withId($userId);

                if ($this->alreadyHolds($user, $roles)) {
                    return [$user, false];
                }

                $this->guardActiveAdministratorsSurvive($userId, $user, $roles);

                $user->changeRoles(...$roles);
                $this->users->save($user);
                $this->eventBus->publish(...$user->pullDomainEvents());

                return [$user, true];
            },
        );

        if ($replaced) {
            $this->revokeSessions->revoke($userId);
        }

        return $user;
    }

    /**
     * Set equality, so neither the order the console sends its checkboxes in nor a duplicated value makes a
     * redundant request look like a change.
     *
     * @param array<Role> $roles
     */
    private function alreadyHolds(User $user, array $roles): bool
    {
        return $this->canonical($user->roles()) === $this->canonical($roles);
    }

    /**
     * @param array<Role> $roles
     *
     * @return list<string>
     */
    private function canonical(array $roles): array
    {
        $values = \array_values(\array_unique(
            \array_map(static fn (Role $role): string => $role->value, $roles),
        ));
        \sort($values);

        return $values;
    }

    /**
     * @param array<Role> $roles
     *
     * @throws LastActiveAdministratorProtected
     */
    private function guardActiveAdministratorsSurvive(string $userId, User $user, array $roles): void
    {
        if (!$this->demotesAnActiveAdministrator($user, $roles)) {
            return;
        }

        if (!$this->administrators->keepsAnActiveAdminWithout($userId)) {
            throw LastActiveAdministratorProtected::forUser($userId);
        }
    }

    /**
     * Whether this change removes an identity that currently counts from the active-administrator pool. A
     * non-`ACTIVE` identity is not in the pool to begin with, so re-roling it can never drain it.
     *
     * @param array<Role> $roles
     */
    private function demotesAnActiveAdministrator(User $user, array $roles): bool
    {
        return $user->isActive()
            && \in_array(Role::ADMIN, $user->roles(), true)
            && !\in_array(Role::ADMIN, $roles, true);
    }
}
