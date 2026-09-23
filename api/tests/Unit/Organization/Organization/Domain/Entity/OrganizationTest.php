<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Organization\Domain\Entity;

use DateTimeImmutable;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Clock\SuiteInstant;
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

        $organization = Organization::provision($id, 'ACME Corp', SuiteInstant::now());

        $this->assertSame($id, $organization->getId());
        $this->assertSame('ACME Corp', $organization->name());
    }

    public function testProvisionSealsCreationTimestamps(): void
    {
        $now = new DateTimeImmutable('2031-03-07T09:15:00+00:00');

        $organization = Organization::provision(Uuid::generate(), 'ACME Corp', $now);

        $this->assertSame($organization->getCreatedAt(), $organization->getUpdatedAt());
        $this->assertSame($now, $organization->getCreatedAt());
    }

    public function testProvisionTrimsSurroundingWhitespaceFromName(): void
    {
        $organization = Organization::provision(Uuid::generate(), '  ACME Corp  ', SuiteInstant::now());

        $this->assertSame('ACME Corp', $organization->name());
    }
}
