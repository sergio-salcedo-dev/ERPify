<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;

final class BankMother
{
    public const string DEFAULT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public static function create(
        string $id = self::DEFAULT_ID,
        string $name = 'Acme Savings',
        string $shortName = 'ACME',
    ): Bank {
        return Bank::create($id, $name, $shortName);
    }

    public static function drained(
        string $id = self::DEFAULT_ID,
        string $name = 'Acme Savings',
        string $shortName = 'ACME',
    ): Bank {
        $bank = self::create($id, $name, $shortName);
        $bank->pullDomainEvents();

        return $bank;
    }

    /**
     * A stored bank that already holds a logo, with no event pending and `updatedAt` at the clock the test pinned.
     */
    public static function withLogo(string $logoImageId, string $id = self::DEFAULT_ID): Bank
    {
        $bank = self::drained($id);
        $bank->changeLogo($logoImageId);

        return $bank;
    }
}
