<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountCreatedDomainEvent;
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

    public function testCreateRecordsCreatedEventWhoseAggregateIdEqualsTheEntityId(): void
    {
        $account = BankAccountMother::create();

        $events = $account->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BankAccountCreatedDomainEvent::class, $events[0]);
        $this->assertSame(BankAccountMother::DEFAULT_ID, $events[0]->aggregateId());
        $this->assertSame(BankAccountMother::DEFAULT_BANK_ID, $events[0]->bankId());
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

    public function testGetHolderNameReturnsTheHolder(): void
    {
        $account = BankAccountMother::create(holderName: 'Globex Corporation');

        $this->assertSame('Globex Corporation', $account->getHolderName());
    }

    public function testGetAliasReturnsTheAliasWhenProvidedAndNullWhenAbsent(): void
    {
        $this->assertSame('Treasury', BankAccountMother::create(alias: 'Treasury')->getAlias());
        $this->assertNull(BankAccountMother::create()->getAlias());
    }
}
