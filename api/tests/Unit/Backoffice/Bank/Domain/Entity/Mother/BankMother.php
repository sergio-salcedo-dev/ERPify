<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother;

use DateTimeImmutable;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Tests\Double\Clock\SuiteInstant;

final class BankMother
{
    public const string DEFAULT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public static function create(
        string $id = self::DEFAULT_ID,
        string $name = 'Acme Savings',
        string $shortName = 'ACME',
        ?DateTimeImmutable $now = null,
    ): Bank {
        return Bank::create($id, $name, $shortName, $now ?? SuiteInstant::now());
    }

    public static function drained(
        string $id = self::DEFAULT_ID,
        string $name = 'Acme Savings',
        string $shortName = 'ACME',
        ?DateTimeImmutable $now = null,
    ): Bank {
        $bank = self::create($id, $name, $shortName, $now);
        $bank->pullDomainEvents();

        return $bank;
    }
}
