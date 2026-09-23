<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Application;

use Erpify\Organization\Membership\Application\GrantMembership;
use Erpify\Organization\Membership\Domain\Exception\OrganizationNotProvisioned;
use Erpify\Organization\Membership\Domain\Exception\UserAlreadyMember;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Double\Clock\SuiteInstant;
use Erpify\Tests\Unit\Organization\Organization\Application\InMemoryOrganizationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GrantMembership::class)]
#[CoversClass(OrganizationNotProvisioned::class)]
#[CoversClass(UserAlreadyMember::class)]
final class GrantMembershipTest extends TestCase
{
    public function testGrantsMembershipInTheProvisionedOrganization(): void
    {
        $clock = FixedClock::at('2031-03-07T09:15:00+00:00');
        $organization = Organization::provision(Uuid::generate(), 'ACME Corp', $clock->now());
        $organizations = new InMemoryOrganizationRepository();
        $organizations->save($organization);

        $memberships = new InMemoryMembershipRepository();
        $userId = Uuid::generate();

        $membership = (new GrantMembership($memberships, $organizations, $clock))->grant($userId);

        $this->assertSame([$membership], $memberships->saved);
        $this->assertSame($userId, $membership->userId());
        $this->assertSame($organization->getId(), $membership->organizationId());
        $this->assertSame($clock->now(), $membership->getCreatedAt());
    }

    public function testRejectsWhenNoOrganizationIsProvisioned(): void
    {
        $granter = new GrantMembership(
            new InMemoryMembershipRepository(),
            new InMemoryOrganizationRepository(),
            SuiteInstant::clock(),
        );

        $this->expectException(OrganizationNotProvisioned::class);

        $granter->grant(Uuid::generate());
    }

    public function testRejectsAMalformedUserIdBeforeTouchingTheRepositories(): void
    {
        // Empty org repo: an unguarded id would surface OrganizationNotProvisioned (or a raw DBAL error at
        // the lookup); InvalidUuidException winning proves the id is validated at ingress, before any lookup.
        $granter = new GrantMembership(
            new InMemoryMembershipRepository(),
            new InMemoryOrganizationRepository(),
            SuiteInstant::clock(),
        );

        $this->expectException(InvalidUuidException::class);

        $granter->grant('not-a-uuid');
    }

    public function testRejectsASecondMembershipForTheSameUser(): void
    {
        $memberships = new InMemoryMembershipRepository();
        $granter = new GrantMembership($memberships, $this->organizationsWith('ACME Corp'), SuiteInstant::clock());
        $userId = Uuid::generate();
        $granter->grant($userId);

        $this->expectException(UserAlreadyMember::class);

        $granter->grant($userId);
    }

    private function organizationsWith(string $name): InMemoryOrganizationRepository
    {
        $organizations = new InMemoryOrganizationRepository();
        $organizations->save(Organization::provision(Uuid::generate(), $name, SuiteInstant::now()));

        return $organizations;
    }
}
