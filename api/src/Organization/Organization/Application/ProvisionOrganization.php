<?php

declare(strict_types=1);

namespace Erpify\Organization\Organization\Application;

use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Organization\Organization\Domain\Exception\OrganizationAlreadyProvisioned;
use Erpify\Organization\Organization\Domain\Repository\OrganizationRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Shared\Validation\Application\Validator;

/**
 * Provisions the installation's single organization: the server mints the id (UUID v7), the entity
 * constraints are enforced, and it is rejected if one already exists — the "one organization per
 * installation" rule lives here, not in the schema, so it relaxes without a migration when tenancy opens.
 */
final readonly class ProvisionOrganization
{
    public function __construct(
        private OrganizationRepository $organizations,
        private Validator $validator,
    ) {
    }

    /**
     * @throws OrganizationAlreadyProvisioned
     */
    public function provision(string $name): Organization
    {
        if ($this->organizations->findTheOne() instanceof Organization) {
            throw new OrganizationAlreadyProvisioned();
        }

        $organization = Organization::provision(Uuid::generate(), $name);

        $this->validator->ensure($organization);
        $this->organizations->save($organization);

        return $organization;
    }
}
