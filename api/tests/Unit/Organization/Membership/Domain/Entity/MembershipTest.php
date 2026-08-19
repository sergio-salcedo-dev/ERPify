<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Domain\Entity;

use Erpify\Organization\Membership\Domain\Entity\Membership;
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
    public function testGrantBindsTheUserToTheOrganization(): void
    {
        $id = Uuid::generate();
        $userId = Uuid::generate();
        $organizationId = Uuid::generate();

        $membership = Membership::grant($id, $userId, $organizationId);

        $this->assertSame($id, $membership->getId());
        $this->assertSame($userId, $membership->userId());
        $this->assertSame($organizationId, $membership->organizationId());
    }

    public function testGrantRejectsAMalformedUserId(): void
    {
        $this->expectException(InvalidUuidException::class);

        Membership::grant(Uuid::generate(), 'not-a-uuid', Uuid::generate());
    }

    public function testGrantRejectsAMalformedOrganizationId(): void
    {
        $this->expectException(InvalidUuidException::class);

        Membership::grant(Uuid::generate(), Uuid::generate(), 'not-a-uuid');
    }
}
