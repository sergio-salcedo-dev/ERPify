<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Shared\Access\Domain\Role;

final class UserMother
{
    public const string DEFAULT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public const string DEFAULT_EMAIL = 'alice@erpify.test';

    /**
     * An opaque, obviously-fake stand-in for a precomputed hash: the domain never inspects its format,
     * only that it is non-empty. Real hashing lives in Infrastructure, never in the domain.
     */
    public const string DEFAULT_HASH = 'hashed-password-placeholder';

    /**
     * An already-credentialed, `ACTIVE` identity.
     *
     * @param list<Role>|null $roles null defaults to a single AUDIT_READER; pass `[]` for a role-less user
     */
    public static function create(
        string $id = self::DEFAULT_ID,
        string $email = self::DEFAULT_EMAIL,
        ?HashedPassword $password = null,
        ?array $roles = null,
    ): User {
        return User::register(
            $id,
            $email,
            $password ?? HashedPassword::fromHash(self::DEFAULT_HASH),
            ...($roles ?? [Role::AUDIT_READER]),
        );
    }

    /**
     * An `INVITED` identity with no credential set yet — the pre-activation state used to exercise the
     * nullable password and the pre-identity admission arm.
     *
     * @param list<Role>|null $roles null defaults to a single AUDIT_READER; pass `[]` for a role-less user
     */
    public static function invited(
        string $id = self::DEFAULT_ID,
        string $email = self::DEFAULT_EMAIL,
        ?array $roles = null,
    ): User {
        return User::invite($id, $email, ...($roles ?? [Role::AUDIT_READER]));
    }
}
