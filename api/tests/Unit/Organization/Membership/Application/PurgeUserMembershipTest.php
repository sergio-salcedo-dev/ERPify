<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Application;

use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The published seam a GDPR erasure consumes to drop the link that admitted a subject to the organization.
 * It hard-deletes the row scoped to that user — never a neighbour's — and guards the id shape before the
 * store is touched at all.
 *
 * @internal
 */
#[CoversClass(PurgeUserMembership::class)]
final class PurgeUserMembershipTest extends TestCase
{
    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50';

    public function testHardDeletesTheMembershipOfTheUserAndReturnsTheCount(): void
    {
        $userId = Uuid::generate();
        $other = Uuid::generate();
        $memberships = new InMemoryMembershipRepository();
        $memberships->save($this->membershipFor($userId));
        $memberships->save($this->membershipFor($other));

        $deleted = (new PurgeUserMembership($memberships))->purge($userId);

        $this->assertSame(1, $deleted);
        $this->assertNotInstanceOf(Membership::class, $memberships->findByUserId($userId));
        $this->assertInstanceOf(Membership::class, $memberships->findByUserId($other));
    }

    public function testIsIdempotentForAUserWithNoMembership(): void
    {
        $memberships = new InMemoryMembershipRepository();

        $this->assertSame(0, (new PurgeUserMembership($memberships))->purge(Uuid::generate()));
    }

    public function testRejectsAMalformedUserIdBeforeTouchingTheStore(): void
    {
        $memberships = new InMemoryMembershipRepository();
        $memberships->save($this->membershipFor(Uuid::generate()));

        $this->expectException(InvalidUuidException::class);

        (new PurgeUserMembership($memberships))->purge('not-a-uuid');
    }

    private function membershipFor(string $userId): Membership
    {
        $membership = Membership::grant(Uuid::generate(), $userId, self::ORGANIZATION_ID, Role::VIEWER);
        $membership->pullDomainEvents();

        return $membership;
    }
}
