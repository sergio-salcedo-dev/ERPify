<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Domain\Entity;

use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Membership::class)]
final class MembershipTest extends TestCase
{
    public function testGrantBindsUserToOrganizationWithRoles(): void
    {
        $id = Uuid::generate();
        $userId = Uuid::generate();
        $organizationId = Uuid::generate();

        $membership = Membership::grant($id, $userId, $organizationId, Role::ADMIN);

        $this->assertSame($id, $membership->getId());
        $this->assertSame($userId, $membership->userId());
        $this->assertSame($organizationId, $membership->organizationId());
        $this->assertSame([Role::ADMIN], $membership->roles());
        $this->assertTrue($membership->hasRole(Role::ADMIN));
        $this->assertFalse($membership->hasRole(Role::VIEWER));
    }

    public function testGrantDeduplicatesRoles(): void
    {
        $membership = Membership::grant(
            Uuid::generate(),
            Uuid::generate(),
            Uuid::generate(),
            Role::EDITOR,
            Role::EDITOR,
            Role::VIEWER,
        );

        $this->assertSame([Role::EDITOR, Role::VIEWER], $membership->roles());
    }

    public function testGrantAcceptsAnEmptyRoleSet(): void
    {
        $membership = Membership::grant(Uuid::generate(), Uuid::generate(), Uuid::generate());

        $this->assertSame([], $membership->roles());
    }

    public function testGrantRejectsAMalformedUserId(): void
    {
        $this->expectException(InvalidUuidException::class);

        Membership::grant(Uuid::generate(), 'not-a-uuid', Uuid::generate(), Role::ADMIN);
    }

    public function testGrantRejectsAMalformedOrganizationId(): void
    {
        $this->expectException(InvalidUuidException::class);

        Membership::grant(Uuid::generate(), Uuid::generate(), 'not-a-uuid', Role::ADMIN);
    }
}
