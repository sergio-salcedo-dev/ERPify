<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Security;

use Erpify\Backoffice\Identity\Domain\Email;
use Erpify\Backoffice\Identity\Domain\Entity\User;
use Erpify\Backoffice\Identity\Domain\Exception\InvalidEmail;
use Erpify\Backoffice\Identity\Domain\Repository\UserRepository;
use Override;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads the session user by its email identifier. A blank or malformed identifier is a "user not found",
 * never a 500: {@see Email::from()} rejects blanks and the lookup is a pure {@see UserRepository::findByEmail()}
 * that never validates, so this provider owns the raw-identifier -> not-found translation.
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class UserProvider implements UserProviderInterface
{
    public function __construct(private UserRepository $users)
    {
    }

    #[Override]
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        try {
            $email = Email::from($identifier);
        } catch (InvalidEmail) {
            throw new UserNotFoundException();
        }

        $user = $this->users->findByEmail($email);

        if (!$user instanceof User) {
            throw new UserNotFoundException();
        }

        return new SecurityUser($user);
    }

    #[Override]
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(\sprintf('Cannot refresh user of type "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    #[Override]
    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class || \is_subclass_of($class, SecurityUser::class);
    }
}
