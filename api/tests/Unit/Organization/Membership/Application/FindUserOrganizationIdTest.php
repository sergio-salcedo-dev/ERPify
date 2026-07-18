<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Application;

use Erpify\Organization\Membership\Application\FindUserOrganizationId;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Exception\MembershipNotFound;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FindUserOrganizationId::class)]
final class FindUserOrganizationIdTest extends TestCase
{
    public function testResolvesTheOrganizationTheUserBelongsTo(): void
    {
        $userId = Uuid::generate();
        $organizationId = Uuid::generate();
        $memberships = new InMemoryMembershipRepository();
        $memberships->save(Membership::grant(Uuid::generate(), $userId, $organizationId, Role::ADMIN));

        $resolved = (new FindUserOrganizationId($memberships))->of($userId);

        $this->assertSame($organizationId, $resolved);
    }

    public function testRaisesMembershipNotFoundWhenTheUserHasNoMembership(): void
    {
        $finder = new FindUserOrganizationId(new InMemoryMembershipRepository());

        $this->expectException(MembershipNotFound::class);

        $finder->of(Uuid::generate());
    }

    public function testRejectsAMalformedUserIdBeforeTheLookup(): void
    {
        $finder = new FindUserOrganizationId(new InMemoryMembershipRepository());

        $this->expectException(InvalidUuidException::class);

        $finder->of('not-a-uuid');
    }
}
