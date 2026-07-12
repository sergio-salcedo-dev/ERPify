<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Enum\Role;
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

    /**
     * `?string` because an `INVITED` identity has no credential yet: the base
     * {@see PasswordAuthenticatedUserInterface::getPassword()} contract is already nullable, and the
     * firewall's credentials check treats a null password as "cannot authenticate" — an `INVITED` login is
     * additionally stopped earlier by the user checker's pre-auth arm, before any password verification.
     */
    #[Override]
    public function getPassword(): ?string
    {
        return $this->user->passwordHash()?->toString();
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

    /**
     * The lifecycle state the admission {@see UserChecker} reads to decide whether this identity may be
     * admitted to a session (only `ACTIVE` is).
     */
    public function status(): IdentityStatus
    {
        return $this->user->status();
    }

    /**
     * Whether the wrapped identity's lockout is still in force at `$now` — the second signal the admission
     * {@see UserChecker} reads (alongside {@see status()}), orthogonal to the lifecycle state, so it can wall a
     * locked-but-`ACTIVE` identity without the domain ever implementing a Symfony contract.
     */
    public function isLockedAt(DateTimeImmutable $now): bool
    {
        return $this->user->isLockedAt($now);
    }

    /**
     * The wrapped identity's UUID, for audit actor attribution. Deliberately `?string`: this is read on
     * the audit hot path (sealed on every request), so it must degrade rather than throw — a caller with
     * no id assigned falls back to a non-user actor instead of surfacing a 500. In practice the id is
     * always present (assigned before persist), so this only ever returns null for an unsaved identity.
     */
    public function id(): ?string
    {
        return $this->user->getId();
    }
}
