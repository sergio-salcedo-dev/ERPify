<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use DateInterval;
use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SecurityUser::class)]
final class SecurityUserTest extends TestCase
{
    public function testExposesTheEmailAsTheSecurityIdentifier(): void
    {
        $securityUser = new SecurityUser(UserMother::create());

        $this->assertSame(UserMother::DEFAULT_EMAIL, $securityUser->getUserIdentifier());
    }

    public function testExposesTheStoredHashAsThePassword(): void
    {
        $securityUser = new SecurityUser(UserMother::create(password: HashedPassword::fromHash('the-stored-hash')));

        $this->assertSame('the-stored-hash', $securityUser->getPassword());
    }

    public function testHasNoPasswordWhenTheIdentityHasNotSetOne(): void
    {
        // An INVITED identity has no credential yet; the credentials check treats a null password as
        // "cannot authenticate" (the pre-auth arm stops it earlier still).
        $securityUser = new SecurityUser(UserMother::invited());

        $this->assertNull($securityUser->getPassword());
    }

    public function testMapsDomainRolesToPrefixedSymfonyRoles(): void
    {
        $securityUser = new SecurityUser(UserMother::create(roles: [Role::AUDIT_READER]));

        $this->assertSame(['ROLE_AUDIT_READER'], $securityUser->getRoles());
    }

    public function testHasNoRolesWhenTheUserCarriesNone(): void
    {
        $securityUser = new SecurityUser(UserMother::create(roles: []));

        $this->assertSame([], $securityUser->getRoles());
    }

    public function testExposesTheWrappedUuidForAuditAttribution(): void
    {
        $securityUser = new SecurityUser(UserMother::create(id: UserMother::DEFAULT_ID));

        $this->assertSame(UserMother::DEFAULT_ID, $securityUser->id());
    }

    public function testExposesTheIdentityLifecycleStatusForAdmission(): void
    {
        $securityUser = new SecurityUser(UserMother::invited());

        $this->assertSame(IdentityStatus::INVITED, $securityUser->status());
    }

    public function testReportsWhetherTheWrappedIdentityIsLockedAtAnInstant(): void
    {
        $lockedAt = new DateTimeImmutable('2026-07-11T12:00:00+00:00');
        $user = UserMother::create();

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($lockedAt);
        }

        $securityUser = new SecurityUser($user);

        $this->assertTrue($securityUser->isLockedAt($lockedAt));
        $this->assertFalse($securityUser->isLockedAt($lockedAt->add(new DateInterval('PT16M'))));
    }
}
