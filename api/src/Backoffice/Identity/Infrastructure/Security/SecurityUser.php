<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Security;

use Erpify\Backoffice\Identity\Domain\Entity\User;
use Erpify\Backoffice\Identity\Domain\Enum\Role;
use Override;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Symfony Security adapter over the framework-free {@see User} aggregate — the single place identity
 * crosses into the framework (the domain never implements a Symfony contract; deptrac forbids it).
 *
 * Roles are emitted with Symfony's `ROLE_` prefix here and only here: the mapping is one-way
 * Domain -> Infrastructure -> Symfony, so nothing ever maps a `ROLE_*` string back to {@see Role}.
 */
final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(private User $user)
    {
    }

    #[Override]
    public function getUserIdentifier(): string
    {
        return $this->user->email();
    }

    #[Override]
    public function getPassword(): string
    {
        return $this->user->passwordHash()->toString();
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function getRoles(): array
    {
        return \array_map(
            static fn (Role $role): string => 'ROLE_' . $role->value,
            $this->user->roles(),
        );
    }
}
