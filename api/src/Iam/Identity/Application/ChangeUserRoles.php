<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\LastActiveAdministratorProtected;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
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
 *    Because that call is conditional, its lock cannot be the one that protects the write: the aggregate is
 *    read through {@see UserRepository::findByIdForUpdate()} so the target row is locked and re-hydrated on
 *    every path. Both decisions below — whether the set already matches, and whether this demotes an active
 *    administrator — then read committed state rather than a snapshot a rival transaction may already have
 *    invalidated. Without it a change that looks harmless against a stale read (the target was not an
 *    administrator when it was loaded) would overwrite an ADMIN granted meanwhile, and consult nothing,
 *    because {@see User::changeRoles()} assigns the whole set rather than a delta.
 * 2. Re-sending the set an identity already holds is a legitimate no-op, not a conflict: the role set is not
 *    unidirectional the way the identity lifecycle is. It short-circuits before mutating, so a redundant save
 *    neither writes, nor emits an event, nor — the reason this matters — tears down live sessions.
 *
 * The post-commit revoke is defence in depth here, not the only barrier. A changed role set already
 * de-authenticates natively (`SecurityUser` is not `EquatableInterface`, so the token comparison covers roles
 * and the next request of the affected identity drops its session), but that path is lazy and reaches only the
 * session making that request; revoking eagerly cuts every device at once. Swallowing a revoke failure mirrors
 * {@see CompletePasswordReset}: the role change already committed.
 *
 * Its object coupling sits one above the default threshold, and deliberately stays there. The six collaborators
 * are each inherent to one atomic act — read under lock, guard the administrator invariant, mutate, publish,
 * record the compliance evidence, tear down sessions — and the three types the audit write speaks in
 * ({@see AuditLogger}, {@see AuditLevel}, {@see AuditResource}) are the seam's vocabulary, not extra
 * responsibilities. Splitting the audit call into its own collaborator for a single caller would buy the metric
 * and cost a reader the ability to see, in one place, that the demotion is recorded — which is precisely the
 * fact the erasure refusal depends on.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class ChangeUserRoles
{
    private const string ROLES_CHANGED_ACTION = 'USER_ROLES_CHANGED';

    public function __construct(
        private UserRepository $users,
        private ActiveAdministratorDirectory $administrators,
        private RevokeSessionsBestEffort $revokeSessions,
        private EventBus $eventBus,
        private AuditLogger $auditLogger,
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
                $user = $this->users->findByIdForUpdate($userId) ?? throw UserNotFound::withId($userId);

                if ($this->alreadyHolds($user, $roles)) {
                    return [$user, false];
                }

                $this->guardActiveAdministratorsSurvive($userId, $user, $roles);

                $held = $this->canonical($user->roles());
                $user->changeRoles(...$roles);
                $this->users->save($user);
                $this->eventBus->publish(...$user->pullDomainEvents());
                $this->auditRoleChange($userId, $held, $this->canonical($roles));

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
     * The one thing about an identity that reaches the compliance trail. `User` deliberately stays out of the
     * write-capture CDC — a field-level diff of it would carry `password_hash` into an append-only store — so
     * without this explicit row a role change would leave no attributable evidence anywhere: the generic
     * access hook only audits `GET`, and the `event_store` entry records that roles changed, never by whom.
     *
     * That gap is load-bearing, not cosmetic: erasure refuses a subject still carrying `ADMIN` precisely so the
     * demotion is recorded first, which is only true if the demotion writes this row. It is written at
     * `SECURITY`, so it lands before the response is sent and is never swallowed. The subject rides in the
     * resource columns and the metadata carries only the role sets — no credential, no email, no diff of the
     * aggregate.
     *
     * @param list<string> $held
     * @param list<string> $assigned
     */
    private function auditRoleChange(string $userId, array $held, array $assigned): void
    {
        $this->auditLogger->log(
            self::ROLES_CHANGED_ACTION,
            AuditLevel::SECURITY,
            AuditResource::of('User', $userId),
            ['previous_roles' => $held, 'new_roles' => $assigned],
        );
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
