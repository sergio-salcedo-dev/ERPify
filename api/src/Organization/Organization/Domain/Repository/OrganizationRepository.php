<?php

declare(strict_types=1);

namespace Erpify\Organization\Organization\Domain\Repository;

use Erpify\Organization\Organization\Domain\Entity\Organization;

/**
 * Aggregate-lifecycle port for {@see Organization}.
 *
 * {@see OrganizationRepository::findTheOne()} materializes the "one organization per installation"
 * assumption: it returns the single provisioned organization (or null before bootstrap). The provisioning
 * use case reads it to reject a second organization; the membership use case reads it to resolve the
 * organization a new member belongs to. Both stay honest the day tenancy opens — the method becomes a
 * lookup by tenant rather than a singleton, without touching its callers' shape.
 */
interface OrganizationRepository
{
    public function save(Organization $organization): void;

    public function findById(string $id): ?Organization;

    public function findTheOne(): ?Organization;
}
