<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccount::class)]
final class BankAccountTest extends TestCase
{
    public function testCreateReferencesTheBankByIdentityWithoutABankObject(): void
    {
        $account = BankAccountMother::create();

        $this->assertSame(BankAccountMother::DEFAULT_BANK_ID, $account->getBankId());
    }

    public function testCreateCanonicalizesIbanToUppercaseWithoutWhitespace(): void
    {
        $account = BankAccountMother::create(iban: 'de89 3704 0044 0532 0130 00');

        $this->assertSame('DE89370400440532013000', $account->getIban());
    }

    public function testCreateUppercasesBic(): void
    {
        $account = BankAccountMother::create(bic: 'deutdeffxxx');

        $this->assertSame('DEUTDEFFXXX', $account->getBic());
    }

    public function testCreateDefaultsToActiveStatus(): void
    {
        $account = BankAccountMother::create();

        $this->assertSame(BankAccountStatus::ACTIVE, $account->getStatus());
    }

    public function testCreateRejectsAMalformedBankId(): void
    {
        $this->expectException(InvalidUuidException::class);

        BankAccountMother::create(bankId: 'not-a-uuid');
    }
}
