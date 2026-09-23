<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;

/**
 * Alice fixture factory for {@see Bank}. The domain factory takes the instant it stamps, and YAML cannot
 * name one, so this supplies {@see SeedInstant} and keeps the YAML listing only the bank's own data.
 */
final class BankFixtureFactory
{
    public static function create(string $id, string $name, string $shortName): Bank
    {
        return Bank::create($id, $name, $shortName, SeedInstant::now());
    }
}
