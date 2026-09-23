<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures;

use Erpify\Organization\Organization\Domain\Entity\Organization;

/**
 * Alice fixture factory for {@see Organization}, supplying the instant the domain factory stamps from
 * {@see SeedInstant} because YAML cannot name one.
 */
final class OrganizationFixtureFactory
{
    public static function provision(string $id, string $name): Organization
    {
        return Organization::provision($id, $name, SeedInstant::now());
    }
}
