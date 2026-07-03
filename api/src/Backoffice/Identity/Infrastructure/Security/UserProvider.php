<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Security;

use Erpify\Backoffice\Identity\Domain\Email;
use Erpify\Backoffice\Identity\Domain\Entity\User;
use Erpify\Backoffice\Identity\Domain\Exception\InvalidEmail;
use Erpify\Backoffice\Identity\Domain\Repository\UserRepository;
use Override;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
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
final class UserProvider implements UserProviderInterface
{
    private const string TIMING_PROBE_INPUT = 'timing-equalisation-probe';

    /**
     * Lazily hashed once, then reused, so every not-found response spends exactly one password verification —
     * the same cost as a known email with a wrong password — instead of re-hashing on each probe.
     */
    private ?string $dummyHash = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
    ) {
    }

    #[Override]
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        try {
            $email = Email::from($identifier);
        } catch (InvalidEmail) {
            $this->equaliseTiming();

            throw new UserNotFoundException();
        }

        $user = $this->users->findByEmail($email);

        if (!$user instanceof User) {
            $this->equaliseTiming();

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

    /**
     * Runs one password verification of equivalent cost before signalling "not found" so an unknown or malformed
     * identifier cannot be told apart from a known email with a wrong password by response latency — the
     * normalised 401 body already makes them indistinguishable, this closes the timing side channel too.
     */
    private function equaliseTiming(): void
    {
        $hasher = $this->passwordHasherFactory->getPasswordHasher(SecurityUser::class);
        $dummyHash = $this->dummyHash ??= $hasher->hash(self::TIMING_PROBE_INPUT);

        $hasher->verify($dummyHash, self::TIMING_PROBE_INPUT);
    }
}
