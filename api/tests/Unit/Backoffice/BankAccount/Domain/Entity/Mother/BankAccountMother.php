<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Shared\Kernel\Domain\Enum\Currency;

final class BankAccountMother
{
    public const string DEFAULT_ID = '33333333-3333-7000-8000-000000000001';

    public const string DEFAULT_BANK_ID = '11111111-1111-7000-8000-000000000001';

    public static function create(
        string $id = self::DEFAULT_ID,
        string $bankId = self::DEFAULT_BANK_ID,
        string $holderName = 'Globex Corporation',
        string $iban = 'DE89370400440532013000',
        ?string $bic = null,
        ?string $alias = null,
        Currency $currency = Currency::EUR,
        BankAccountStatus $status = BankAccountStatus::ACTIVE,
    ): BankAccount {
        return BankAccount::create($id, $bankId, $holderName, $iban, $bic, $alias, $currency, $status);
    }
}
