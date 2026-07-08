<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Organization\Application;

use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Organization\Organization\Domain\Repository\OrganizationRepository;
use Override;

/**
 * In-memory {@see OrganizationRepository} that records saves, so a test can assert what a use case
 * provisions. {@see findTheOne()} returns the first saved organization, mirroring the one-per-installation
 * lookup the provisioning and membership use cases rely on.
 *
 * @internal
 */
final class InMemoryOrganizationRepository implements OrganizationRepository
{
    /** @var list<Organization> */
    public array $saved = [];

    #[Override]
    public function save(Organization $organization): void
    {
        $this->saved[] = $organization;
    }

    #[Override]
    public function findById(string $id): ?Organization
    {
        foreach ($this->saved as $organization) {
            if ($organization->getId() === $id) {
                return $organization;
            }
        }

        return null;
    }

    #[Override]
    public function findTheOne(): ?Organization
    {
        return $this->saved[0] ?? null;
    }
}
