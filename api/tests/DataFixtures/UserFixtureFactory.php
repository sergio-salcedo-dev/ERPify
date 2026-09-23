<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Shared\Access\Domain\Role;

/**
 * Fixture factory for {@see User}. Unlike `Bank::create`, `User::register` takes a {@see HashedPassword}
 * value object (never a raw string), so this test-only factory hashes the seed plaintext and wraps it — the
 * domain factory keeps its typed contract, and the fixture YAML carries a readable plaintext (a bcrypt hash's
 * `$` would collide with Alice's variable syntax). A low cost keeps fixture loading fast; the firewall's
 * "auto" hasher verifies it by detecting the bcrypt prefix.
 *
 * `$status` seeds the lifecycle state: `INVITED` and `REVOKED` are built credential-less (the seed password is
 * unused), the second by withdrawing the first; `SUSPENDED` / `DEACTIVATED` are built as an active identity
 * then transitioned, so their credential still authenticates before the post-identity wall rejects them.
 *
 * `$now` stamps the identity and any transition; YAML never names it, so a seed takes {@see SeedInstant}, while a
 * test ordering identities by `createdAt` passes the instant each one must carry.
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
        ?DateTimeImmutable $now = null,
    ): User {
        $identityStatus = IdentityStatus::from($status);
        $roles = \array_map(Role::from(...), $roleValues);
        $now ??= SeedInstant::now();

        // Both matches are exhaustive on purpose. An `if` chain here fails OPEN: a status it does not name
        // falls through to a credentialed `ACTIVE` identity, so a scenario seeding the new state would silently
        // assert against the wrong subject and pass. A `match` with no default turns that into a failed build.
        $user = match ($identityStatus) {
            IdentityStatus::INVITED, IdentityStatus::REVOKED => User::invite($id, $email, $now, ...$roles),
            IdentityStatus::ACTIVE,
            IdentityStatus::SUSPENDED,
            IdentityStatus::DEACTIVATED => self::credentialed($id, $email, $plainPassword, $now, ...$roles),
        };

        match ($identityStatus) {
            IdentityStatus::REVOKED => $user->revokeInvitation($now),
            IdentityStatus::SUSPENDED => $user->suspend($now),
            IdentityStatus::DEACTIVATED => $user->deactivate($now),
            IdentityStatus::ACTIVE, IdentityStatus::INVITED => null,
        };

        return $user;
    }

    private static function credentialed(
        string $id,
        string $email,
        string $plainPassword,
        DateTimeImmutable $now,
        Role ...$roles,
    ): User {
        $passwordHash = \password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 4]);

        return User::register($id, $email, HashedPassword::fromHash($passwordHash), $now, ...$roles);
    }
}
