<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Organization\Domain\Entity;

use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Organization::class)]
final class OrganizationTest extends TestCase
{
    public function testProvisionRetainsIdentityAndName(): void
    {
        $id = Uuid::generate();

        $organization = Organization::provision($id, 'ACME Corp');

        $this->assertSame($id, $organization->getId());
        $this->assertSame('ACME Corp', $organization->name());
    }

    public function testProvisionSealsCreationTimestamps(): void
    {
        $organization = Organization::provision(Uuid::generate(), 'ACME Corp');

        $this->assertSame($organization->getCreatedAt(), $organization->getUpdatedAt());
    }

    public function testProvisionTrimsSurroundingWhitespaceFromName(): void
    {
        $organization = Organization::provision(Uuid::generate(), '  ACME Corp  ');

        $this->assertSame('ACME Corp', $organization->name());
    }
}
