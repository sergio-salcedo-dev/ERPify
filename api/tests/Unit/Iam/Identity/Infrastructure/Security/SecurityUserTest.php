<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

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
}
