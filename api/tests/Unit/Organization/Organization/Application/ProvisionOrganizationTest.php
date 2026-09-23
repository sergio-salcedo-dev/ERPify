<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Organization\Application;

use Erpify\Organization\Organization\Application\ProvisionOrganization;
use Erpify\Organization\Organization\Domain\Exception\OrganizationAlreadyProvisioned;
use Erpify\Shared\Validation\Application\Validator;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Double\Clock\SuiteInstant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(ProvisionOrganization::class)]
#[CoversClass(OrganizationAlreadyProvisioned::class)]
final class ProvisionOrganizationTest extends TestCase
{
    public function testProvisionsAndPersistsTheOrganization(): void
    {
        $clock = FixedClock::at('2031-03-07T09:15:00+00:00');
        $organizations = new InMemoryOrganizationRepository();
        $provisioner = new ProvisionOrganization($organizations, $this->passingValidator(), $clock);

        $organization = $provisioner->provision('ACME Corp');

        $this->assertSame([$organization], $organizations->saved);
        $this->assertSame('ACME Corp', $organization->name());
        $this->assertNotNull($organization->getId());
        $this->assertSame($clock->now(), $organization->getCreatedAt());
    }

    public function testRejectsASecondOrganization(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $provisioner = new ProvisionOrganization($organizations, $this->passingValidator(), SuiteInstant::clock());
        $provisioner->provision('ACME Corp');

        $this->expectException(OrganizationAlreadyProvisioned::class);

        $provisioner->provision('Second Corp');
    }

    private function passingValidator(): Validator
    {
        $inner = $this->createStub(ValidatorInterface::class);
        $inner->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($inner);
    }
}
