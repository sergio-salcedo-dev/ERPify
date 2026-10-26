<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Application;

use Erpify\Organization\Membership\Application\FindUserOrganizationId;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Exception\MembershipCardinalityViolated;
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
        $memberships->save(Membership::grant(Uuid::generate(), $userId, $organizationId));

        $resolved = (new FindUserOrganizationId($memberships))->of($userId);

        $this->assertSame($organizationId, $resolved);
    }

    /**
     * The absence is an answer the caller interprets in its own vocabulary, so the seam reports it rather than
     * raising an exception of this context across the boundary.
     */
    public function testAnswersNullWhenTheUserHasNoMembership(): void
    {
        $memberships = new InMemoryMembershipRepository();
        $memberships->save(Membership::grant(Uuid::generate(), Uuid::generate(), Uuid::generate()));

        $this->assertNull((new FindUserOrganizationId($memberships))->of(Uuid::generate()));
    }

    /**
     * Several memberships are a broken invariant, not an answer: the seam lets the refusal through rather than
     * resolving the user to either organization.
     */
    public function testAUserWithSeveralMembershipsIsRefusedRatherThanResolved(): void
    {
        $userId = Uuid::generate();
        $memberships = new InMemoryMembershipRepository();
        $memberships->save(Membership::grant(Uuid::generate(), $userId, Uuid::generate()));
        $memberships->save(Membership::grant(Uuid::generate(), $userId, Uuid::generate()));

        $this->expectException(MembershipCardinalityViolated::class);

        (new FindUserOrganizationId($memberships))->of($userId);
    }

    public function testRejectsAMalformedUserIdBeforeTheLookup(): void
    {
        $finder = new FindUserOrganizationId(new InMemoryMembershipRepository());

        $this->expectException(InvalidUuidException::class);

        $finder->of('not-a-uuid');
    }
}
