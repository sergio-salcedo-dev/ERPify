<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use LogicException;

/**
 * Alice fixture factory for {@see Membership}. `Membership::grant` references the user and organization by
 * id (never by association), so this test-only factory resolves both ids from the referenced fixtures and
 * maps the readable role-value list to {@see Role} enums — the domain factory keeps its typed contract.
 */
final class MembershipFixtureFactory
{
    /**
     * @param list<string> $roleValues
     */
    public static function create(User $user, Organization $organization, array $roleValues = []): Membership
    {
        $userId = $user->getId() ?? throw new LogicException('Fixture user must have an id.');
        $organizationId = $organization->getId() ?? throw new LogicException('Fixture organization must have an id.');

        return Membership::grant(
            Uuid::generate(),
            $userId,
            $organizationId,
            ...\array_map(Role::from(...), $roleValues),
        );
    }
}
