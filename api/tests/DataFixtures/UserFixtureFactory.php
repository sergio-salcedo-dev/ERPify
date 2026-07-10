<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Domain\HashedPassword;

/**
 * Fixture factory for {@see User}. Unlike `Bank::create`, `User::register` takes a {@see HashedPassword}
 * value object (never a raw string), so this test-only factory hashes the seed plaintext and wraps it — the
 * domain factory keeps its typed contract, and the fixture YAML carries a readable plaintext (a bcrypt hash's
 * `$` would collide with Alice's variable syntax). A low cost keeps fixture loading fast; the firewall's
 * "auto" hasher verifies it by detecting the bcrypt prefix.
 *
 * `$status` seeds the lifecycle state: `INVITED` is built credential-less (the seed password is unused);
 * `SUSPENDED` / `DEACTIVATED` are built as an active identity then transitioned, so their credential still
 * authenticates before the post-identity wall rejects them.
 */
final class UserFixtureFactory
{
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
        $identityStatus = IdentityStatus::from($status);
        $roles = \array_map(Role::from(...), $roleValues);

        if (IdentityStatus::INVITED === $identityStatus) {
            return User::invite($id, $email, ...$roles);
        }

        $passwordHash = \password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 4]);
        $user = User::register($id, $email, HashedPassword::fromHash($passwordHash), ...$roles);

        if (IdentityStatus::SUSPENDED === $identityStatus) {
            $user->suspend();
        }

        if (IdentityStatus::DEACTIVATED === $identityStatus) {
            $user->deactivate();
        }

        return $user;
    }
}
