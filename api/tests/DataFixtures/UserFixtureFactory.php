<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Shared\Access\Domain\Role;

/**
 * Fixture factory for {@see User}. Unlike `Bank::create`, `User::register` takes a {@see HashedPassword}
 * value object (never a raw string), so this test-only factory wraps an already-minted hash and keeps the
 * domain factory's typed contract.
 *
 * Two entry points, told apart by who can reach the configured hasher. The Alice seed calls
 * {@see self::createWithPasswordHash()} with a hash minted by {@see Provider\SeedCredentialProvider}, i.e. by
 * the hasher the firewall configures for the environment being seeded — a seed hashed cheaper than that
 * configuration would answer a wrong password faster than the login's timing floor answers an unknown address,
 * which is an existence signal on every environment built from these fixtures. Kernel-free and functional tests
 * call {@see self::create()}, which hashes a plaintext itself at the test environment's configured parameters,
 * because a unit test has no container to ask; that the two agree is pinned, not assumed
 * ({@see \Erpify\Tests\Functional\Iam\Identity\SeededCredentialCostFunctionalTest}).
 *
 * `$status` seeds the lifecycle state: `INVITED` and `REVOKED` are built credential-less (the seed hash is
 * unused), the second by withdrawing the first; `SUSPENDED` / `DEACTIVATED` are built as an active identity
 * then transitioned, so their credential still authenticates before the post-identity wall rejects them.
 */
final class UserFixtureFactory
{
    /**
     * The bcrypt cost the test environment configures for the firewall's hasher. A plaintext hashed here must
     * cost what a hash minted by the container costs in that environment, or a test comparing the two paths
     * would compare different work.
     */
    public const int TEST_ENVIRONMENT_BCRYPT_COST = 4;

    /**
     * @param list<string> $roleValues
     */
    public static function create(
        string $id,
        string $email,
        string $plainPassword,
        array $roleValues = [],
        string $status = 'ACTIVE',
    ): User {
        $passwordHash = \password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => self::TEST_ENVIRONMENT_BCRYPT_COST]);

        return self::createWithPasswordHash($id, $email, $passwordHash, $roleValues, $status);
    }

    /**
     * @param list<string> $roleValues
     */
    public static function createWithPasswordHash(
        string $id,
        string $email,
        string $passwordHash,
        array $roleValues = [],
        string $status = 'ACTIVE',
    ): User {
        $identityStatus = IdentityStatus::from($status);
        $roles = \array_map(Role::from(...), $roleValues);

        // Both matches are exhaustive on purpose. An `if` chain here fails OPEN: a status it does not name
        // falls through to a credentialed `ACTIVE` identity, so a scenario seeding the new state would silently
        // assert against the wrong subject and pass. A `match` with no default turns that into a failed build.
        $user = match ($identityStatus) {
            IdentityStatus::INVITED, IdentityStatus::REVOKED => User::invite($id, $email, ...$roles),
            IdentityStatus::ACTIVE,
            IdentityStatus::SUSPENDED,
            IdentityStatus::DEACTIVATED => User::register(
                $id,
                $email,
                HashedPassword::fromHash($passwordHash),
                ...$roles,
            ),
        };

        match ($identityStatus) {
            IdentityStatus::REVOKED => $user->revokeInvitation(),
            IdentityStatus::SUSPENDED => $user->suspend(),
            IdentityStatus::DEACTIVATED => $user->deactivate(),
            IdentityStatus::ACTIVE, IdentityStatus::INVITED => null,
        };

        return $user;
    }
}
