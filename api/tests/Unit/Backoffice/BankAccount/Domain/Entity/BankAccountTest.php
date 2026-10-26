<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountCreatedDomainEvent;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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

    public function testCreateAndUpdateFoldTheHolderNameIntoItsSearchTwinKeepingTheDisplayAsTyped(): void
    {
        $account = BankAccountMother::create(holderName: '  José Muñoz Ibáñez ');

        $this->assertSame('  José Muñoz Ibáñez ', $account->getHolderName());
        $this->assertSame('jose munoz ibanez', $this->holderNameTwinOf($account));

        $account->update('Strauß Ångström', 'DE89370400440532013000', null, null, Currency::EUR);

        $this->assertSame('Strauß Ångström', $account->getHolderName());
        $this->assertSame('strauss angstrom', $this->holderNameTwinOf($account));
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

    /**
     * The twin has no accessor — nothing outside the mapping and the search reads it — so the test reads the
     * property the ORM persists.
     */
    private function holderNameTwinOf(BankAccount $account): string
    {
        $twin = (new ReflectionProperty(BankAccount::class, 'holderNameNormalized'))->getValue($account);
        $this->assertIsString($twin);

        return $twin;
    }
}
