<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Shared\Kernel\Domain\Enum\Currency;

/**
 * Alice fixture factory for {@see BankAccount}. The domain factory takes the instant it stamps between the
 * required fields and the optional ones, which YAML's positional lists cannot supply, so this keeps the
 * domain factory's order minus the instant and fills it with {@see SeedInstant}.
 */
final class BankAccountFixtureFactory
{
    public static function create(
        string $id,
        string $bankId,
        string $holderName,
        string $iban,
        ?string $bic = null,
        ?string $alias = null,
        Currency $currency = Currency::EUR,
        BankAccountStatus $status = BankAccountStatus::ACTIVE,
    ): BankAccount {
        return BankAccount::create(
            $id,
            $bankId,
            $holderName,
            $iban,
            SeedInstant::now(),
            $bic,
            $alias,
            $currency,
            $status,
        );
    }
}
