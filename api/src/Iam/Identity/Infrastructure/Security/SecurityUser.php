<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Exception\InvalidHashedPassword;
use Erpify\Shared\Access\Domain\Role;
use Override;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Symfony Security adapter over the framework-free {@see User} aggregate — the single place identity
 * crosses into the framework (the domain never implements a Symfony contract; deptrac forbids it).
 *
 * Roles are emitted with Symfony's `ROLE_` prefix here and only here: the mapping is one-way
 * Domain -> Infrastructure -> Symfony, so nothing ever maps a `ROLE_*` string back to {@see Role}.
 */
final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
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
     * Whether the copy of this identity carried in the session still matches the one the provider just
     * reloaded — the firewall's own question, asked on every authenticated request, and this interface's only
     * consumer in the security stack ({@see \Symfony\Component\Security\Http\Firewall\ContextListener}).
     *
     * It is answered here because Symfony's default comparison reads the credential straight off the
     * deserialised copy, and that copy reaches the comparison through no provider: a stored hash
     * {@see \Erpify\Iam\Identity\Domain\HashedPassword} refuses would escape from inside the firewall, where it
     * carries no marker and lands as a 500 on every request the cookie is sent with. An unreadable credential
     * is therefore equal to nothing — this copy included — so the token is dropped and the caller is sent back
     * to the door, the same fail-closed {@see UserProvider} applies to the row it loads.
     *
     * Each of the three facts compared answers to a caller. The CREDENTIAL, because both replacement flows
     * ({@see \Erpify\Iam\Identity\Application\CompletePasswordReset},
     * {@see \Erpify\Iam\Identity\Application\ChangeMyPassword}) swallow a failed session revoke on the stated
     * grounds that a credential change de-authenticates the old sessions natively — this comparison IS that
     * native de-authentication, so dropping it would silently degrade their containment to whatever the
     * best-effort revoke happened to achieve. The ROLES, so a revocation bites on the next request rather than
     * at the session's TTL. The IDENTIFIER is the one the reload was resolved BY, so it cannot differ today; it
     * is kept for a provider that comes to resolve the reload by something else, such as the immutable id.
     *
     * Roles compare as a SET — their order in the JSON column is not a fact about the identity, and ejecting
     * live sessions over a reordered column would be a denial of service dressed as a security check.
     */
    #[Override]
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        try {
            $credential = $this->getPassword();
            $theirs = $user->getPassword();
        } catch (InvalidHashedPassword) {
            return false;
        }

        return $this->getUserIdentifier() === $user->getUserIdentifier()
            && $credential === $theirs
            && $this->sameRolesAs($user);
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

    private function sameRolesAs(self $user): bool
    {
        $mine = $this->getRoles();
        $theirs = $user->getRoles();

        \sort($mine);
        \sort($theirs);

        return $mine === $theirs;
    }
}
