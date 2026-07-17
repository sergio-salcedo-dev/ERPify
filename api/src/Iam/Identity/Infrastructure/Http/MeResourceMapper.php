<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Application\Resource\MeResource;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionResolver;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use LogicException;

/**
 * Maps the authenticated {@see SecurityUser} to the {@see MeResource} wire contract. The `ROLE_` prefix the
 * firewall adds is a one-way Domain → Symfony mapping, so it is stripped here to hand the PWA the domain role
 * names it stores — the reverse of {@see SecurityUser::getRoles()}.
 *
 * The bare names that strip yields are exactly what {@see PermissionResolver} expects, so the permission set
 * is derived from them here in one pass — the resource never sees a `ROLE_`-prefixed token.
 */
final readonly class MeResourceMapper
{
    private const string ROLE_PREFIX = 'ROLE_';

    public function __construct(private PermissionResolver $permissionResolver)
    {
    }

    public function toResource(SecurityUser $user): MeResource
    {
        $roles = $this->stripRolePrefix($user->getRoles());

        return new MeResource(
            id: $user->id() ?? throw new LogicException('An authenticated identity must have an id.'),
            email: $user->getUserIdentifier(),
            roles: $roles,
            permissions: $this->permissionResolver->forRoles($roles),
        );
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function stripRolePrefix(array $roles): array
    {
        return \array_map(
            static fn (string $role): string => \str_starts_with($role, self::ROLE_PREFIX)
                ? \substr($role, \strlen(self::ROLE_PREFIX))
                : $role,
            $roles,
        );
    }
}
