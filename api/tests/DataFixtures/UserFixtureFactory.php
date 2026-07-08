<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Domain\HashedPassword;

/**
 * Alice fixture factory for {@see User}. Unlike `Bank::create`, `User::register` takes a {@see HashedPassword}
 * value object (never a raw string), so this test-only factory hashes the seed plaintext and wraps it — the
 * domain factory keeps its typed contract, and the fixture YAML carries a readable plaintext (a bcrypt hash's
 * `$` would collide with Alice's variable syntax). A low cost keeps fixture loading fast; the firewall's
 * "auto" hasher verifies it by detecting the bcrypt prefix.
 */
final class UserFixtureFactory
{
    /**
     * @param list<string> $roleValues
     */
    public static function create(string $id, string $email, string $plainPassword, array $roleValues = []): User
    {
        $passwordHash = \password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 4]);

        return User::register(
            $id,
            $email,
            HashedPassword::fromHash($passwordHash),
            ...\array_map(Role::from(...), $roleValues),
        );
    }
}
