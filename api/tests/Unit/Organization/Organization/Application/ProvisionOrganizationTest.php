<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Organization\Application;

use Erpify\Organization\Organization\Application\ProvisionOrganization;
use Erpify\Organization\Organization\Domain\Exception\OrganizationAlreadyProvisioned;
use Erpify\Shared\Validation\Application\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(ProvisionOrganization::class)]
final class ProvisionOrganizationTest extends TestCase
{
    public function testProvisionsAndPersistsTheOrganization(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $provisioner = new ProvisionOrganization($organizations, $this->passingValidator());

        $organization = $provisioner->provision('ACME Corp');

        $this->assertSame([$organization], $organizations->saved);
        $this->assertSame('ACME Corp', $organization->name());
        $this->assertNotNull($organization->getId());
    }

    public function testRejectsASecondOrganization(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $provisioner = new ProvisionOrganization($organizations, $this->passingValidator());
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
