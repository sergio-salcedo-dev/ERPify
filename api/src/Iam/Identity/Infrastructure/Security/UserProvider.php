<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Application\PreIdentityTimingFloor;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\InvalidEmail;
use Erpify\Iam\Identity\Domain\Exception\InvalidHashedPassword;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
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
 * Both not-found branches pay the shared {@see PreIdentityTimingFloor} before signalling, so an unknown or
 * malformed identifier cannot be told apart from a known email with a wrong password by response latency —
 * the normalised 401 body already makes them indistinguishable, the floor closes the timing side channel too.
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class UserProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $users,
        private PreIdentityTimingFloor $timingFloor,
    ) {
    }

    #[Override]
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        try {
            $email = Email::from($identifier);
        } catch (InvalidEmail) {
            $this->timingFloor->equalise();

            throw new UserNotFoundException();
        }

        $user = $this->users->findByEmail($email);

        if (!$user instanceof User) {
            $this->timingFloor->equalise();

            throw new UserNotFoundException();
        }

        $securityUser = new SecurityUser($user);

        // The credential is re-hydrated HERE, eagerly, so a stored hash the value object refuses becomes a
        // not-found at the one boundary that owns "may this identity authenticate" — rather than an
        // `InvalidHashedPassword` escaping through `getPassword()` later, from inside the firewall, where it
        // has no marker and lands as a 500 on every authenticated request. The refusal is deliberately the
        // same one an unknown email gets, timing floor included: a caller learns nothing from the difference,
        // and the integrity fault stays loud in the logs rather than in the response.
        //
        // Fail-closed is right for the credential and wrong for the roles, which `User::roles()` filters
        // instead: an unreadable credential means the identity cannot prove anything, while an unreadable
        // role, under a grant-only policy, means only that one grant is unavailable.
        try {
            $securityUser->getPassword();
        } catch (InvalidHashedPassword) {
            $this->timingFloor->equalise();

            throw new UserNotFoundException();
        }

        return $securityUser;
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
