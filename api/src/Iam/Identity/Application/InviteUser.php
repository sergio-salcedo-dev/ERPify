<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Shared\Validation\Application\Validator;

/**
 * Provisions an `INVITED` identity with no credential yet — the invitation onboarding path, the credential-less
 * sibling of {@see CreateUser}. The server mints the id (UUID v7), the aggregate enforces its invariants
 * (canonical email, distinct roles) and {@see Validator::ensure()} runs the entity constraints (`#[Assert\Email]`
 * and the `#[UniqueEntity(email)]` guard) before persisting, so a duplicate email surfaces as a clean validation
 * failure rather than a raw DB error. That check is a SELECT and the write is an INSERT, so a request that
 * commits the same address in between still reaches the unique index; the port translates that into a conflict
 * carrying no address, rather than letting the driver's message — which names one — become the answer. The
 * owner sets the password later by accepting the invitation.
 *
 * This is the published seam the invitation orchestrator funnels through, so it never touches the {@see User}
 * aggregate factory across the context boundary — the same shape the bootstrap uses via {@see CreateUser}.
 *
 * Being that single seam is also why the roles an invitation carries are recorded here rather than at the HTTP
 * edge: both the console endpoint and the `iam:invitation:create` CLI reach an identity's first role set through
 * this method, so one call covers both and the CLI is attributed as `system` instead of leaving no trace at all.
 * Without it an administrator wanting a second administrator off the record would simply invite one rather than
 * promote one, and `audit_log` would hold nothing — the permission names the act on both paths, so the record
 * has to as well.
 */
final readonly class InviteUser
{
    /**
     * Distinct from the role-change action on purpose: an invitation grants a first set, so there is no previous
     * one to diff, and collapsing the two would report a creation as a modification. The metadata shape is kept
     * identical (`previous_roles` empty) so one query answers "every role this person was ever given" across
     * both actions.
     */
    private const string ROLES_GRANTED_ACTION = 'USER_ROLES_GRANTED';

    public function __construct(
        private UserRepository $users,
        private Validator $validator,
        private AuditLogger $auditLogger,
    ) {
    }

    public function invite(string $email, Role ...$roles): User
    {
        $user = User::invite(Uuid::generate(), $email, ...$roles);

        $this->validator->ensure($user);
        $this->users->save($user);

        $this->auditGrant($user);

        return $user;
    }

    /**
     * Written after the save, inside whatever transaction the orchestrator opened, so the record commits with
     * the identity it describes or rolls back with it. The resource type is reached through the class declared
     * to erase it, never re-spelled, so the subject's id here is covered by the same anonymisation pass.
     */
    private function auditGrant(User $user): void
    {
        $this->auditLogger->log(
            self::ROLES_GRANTED_ACTION,
            AuditLevel::SECURITY,
            AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, (string) $user->getId()),
            ['previous_roles' => [], 'new_roles' => $this->canonical($user->roles())],
        );
    }

    /**
     * Sorted and duplicate-free, matching the shape {@see ChangeUserRoles} records, so the two actions are
     * comparable without the reader having to normalise them.
     *
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
}
