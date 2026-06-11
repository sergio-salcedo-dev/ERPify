<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Shared\Domain\Uuid\InvalidUuidException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccount::class)]
final class BankAccountTest extends TestCase
{
    private const string ID = '33333333-3333-7000-8000-000000000001';

    private const string BANK_ID = '11111111-1111-7000-8000-000000000001';

    public function testCreateReferencesTheBankByIdentityWithoutABankObject(): void
    {
        $account = BankAccount::create(self::ID, self::BANK_ID, 'Globex Corporation', 'DE89370400440532013000');

        $this->assertSame(self::BANK_ID, $account->getBankId());
    }

    public function testCreateCanonicalizesIbanToUppercaseWithoutWhitespace(): void
    {
        $account = BankAccount::create(self::ID, self::BANK_ID, 'Globex Corporation', 'de89 3704 0044 0532 0130 00');

        $this->assertSame('DE89370400440532013000', $account->getIban());
    }

    public function testCreateUppercasesBic(): void
    {
        $account = BankAccount::create(
            self::ID,
            self::BANK_ID,
            'Globex Corporation',
            'DE89370400440532013000',
            'deutdeffxxx',
        );

        $this->assertSame('DEUTDEFFXXX', $account->getBic());
    }

    public function testCreateDefaultsToActiveStatus(): void
    {
        $account = BankAccount::create(self::ID, self::BANK_ID, 'Globex Corporation', 'DE89370400440532013000');

        $this->assertSame(BankAccountStatus::ACTIVE, $account->getStatus());
    }

    public function testCreateRejectsAMalformedBankId(): void
    {
        $this->expectException(InvalidUuidException::class);

        BankAccount::create(self::ID, 'not-a-uuid', 'Globex Corporation', 'DE89370400440532013000');
    }
}
