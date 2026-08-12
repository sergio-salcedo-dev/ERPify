<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use DateTimeInterface;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountUpdatedDomainEvent;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Clock\Infrastructure\SymfonyClock;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Idempotence of the descriptive update. Equality is decided against the state the aggregate would
 * persist, never against the arguments, so spacing, casing and the empty string an API caller may send
 * for an absent BIC do not by themselves constitute a change.
 *
 * Every test moves the clock between storing and editing, which is what makes an `updatedAt` that
 * should not have moved observable: a guard that skips the event but still stamps the timestamp alters
 * the persistable state and would pass a test that only counted events.
 *
 * @internal
 */
#[CoversClass(BankAccount::class)]
final class BankAccountUpdateNoOpTest extends TestCase
{
    private const string STORED_AT = '2026-06-14T09:30:00+00:00';

    private const string EDITED_AT = '2026-06-15T11:00:00+00:00';

    private const string HOLDER_NAME = 'Globex Corporation';

    private const string IBAN = 'DE89370400440532013000';

    private const string BIC = 'DEUTDEFFXXX';

    protected function tearDown(): void
    {
        SystemClock::reset();

        parent::tearDown();
    }

    public function testUpdateWithTheStoredValuesRecordsNothingAndLeavesUpdatedAtUntouched(): void
    {
        $account = $this->storedAccount(bic: self::BIC, alias: 'Treasury');

        $account->update(self::HOLDER_NAME, self::IBAN, self::BIC, 'Treasury', Currency::EUR);

        $this->assertSame('Treasury', $account->getAlias());
        $this->assertNoOp($account);
    }

    public function testUpdateComparesTheCanonicalIbanSoWhitespaceOnlyDifferencesAreANoOp(): void
    {
        $account = $this->storedAccount();

        $account->update(self::HOLDER_NAME, 'DE89 3704 0044 0532 0130 00', null, null, Currency::EUR);

        $this->assertSame(self::IBAN, $account->getIban());
        $this->assertNoOp($account);
    }

    public function testUpdateComparesTheCanonicalIbanSoCaseOnlyDifferencesAreANoOp(): void
    {
        $account = $this->storedAccount();

        $account->update(self::HOLDER_NAME, 'de89370400440532013000', null, null, Currency::EUR);

        $this->assertSame(self::IBAN, $account->getIban());
        $this->assertNoOp($account);
    }

    public function testUpdateComparesTheCanonicalBicSoCaseOnlyDifferencesAreANoOp(): void
    {
        $account = $this->storedAccount(bic: self::BIC);

        $account->update(self::HOLDER_NAME, self::IBAN, 'deutdeffxxx', null, Currency::EUR);

        $this->assertSame(self::BIC, $account->getBic());
        $this->assertNoOp($account);
    }

    public function testUpdateTreatsAnEmptyBicAsTheStoredAbsentOneSoItIsANoOp(): void
    {
        $account = $this->storedAccount();

        $account->update(self::HOLDER_NAME, self::IBAN, '', null, Currency::EUR);

        $this->assertNull($account->getBic());
        $this->assertNoOp($account);
    }

    public function testUpdateTreatsAnEmptyAliasAsDistinctFromTheStoredAbsentOneAndMutates(): void
    {
        // The sibling asymmetry: `bic` canonicalizes '' to null, `alias` is stored raw. Two different
        // persisted states, so the write is a real change and must publish.
        $account = $this->storedAccount();

        $account->update(self::HOLDER_NAME, self::IBAN, null, '', Currency::EUR);

        $this->assertSame('', $account->getAlias());
        $this->assertMutated($account);
    }

    public function testUpdateOfTheHolderNameAloneMutatesBumpsUpdatedAtAndRecordsTheUpdatedEvent(): void
    {
        $account = $this->storedAccount(bic: self::BIC, alias: 'Treasury');

        $account->update('Globex Renamed', self::IBAN, self::BIC, 'Treasury', Currency::EUR);

        $this->assertSame('Globex Renamed', $account->getHolderName());
        $this->assertMutated($account);
    }

    public function testUpdateOfTheIbanAloneMutatesBumpsUpdatedAtAndRecordsTheUpdatedEvent(): void
    {
        $account = $this->storedAccount();

        $account->update(self::HOLDER_NAME, 'FR1420041010050500013M02606', null, null, Currency::EUR);

        $this->assertSame('FR1420041010050500013M02606', $account->getIban());
        $this->assertMutated($account);
    }

    public function testUpdateOfTheBicAloneMutatesBumpsUpdatedAtAndRecordsTheUpdatedEvent(): void
    {
        $account = $this->storedAccount(bic: self::BIC);

        $account->update(self::HOLDER_NAME, self::IBAN, 'BNPAFRPPXXX', null, Currency::EUR);

        $this->assertSame('BNPAFRPPXXX', $account->getBic());
        $this->assertMutated($account);
    }

    private function storedAccount(?string $bic = null, ?string $alias = null): BankAccount
    {
        SystemClock::set(new SymfonyClock(new MockClock(self::STORED_AT)));

        $account = BankAccountMother::drained(
            holderName: self::HOLDER_NAME,
            iban: self::IBAN,
            bic: $bic,
            alias: $alias,
        );

        SystemClock::set(new SymfonyClock(new MockClock(self::EDITED_AT)));

        return $account;
    }

    private function assertNoOp(BankAccount $account): void
    {
        $this->assertSame(self::HOLDER_NAME, $account->getHolderName());
        $this->assertSame(self::STORED_AT, $account->getUpdatedAt()->format(DateTimeInterface::ATOM));
        $this->assertSame([], $account->pullDomainEvents());
    }

    private function assertMutated(BankAccount $account): void
    {
        $this->assertSame(self::EDITED_AT, $account->getUpdatedAt()->format(DateTimeInterface::ATOM));

        $events = $account->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BankAccountUpdatedDomainEvent::class, $events[0]);
        $this->assertSame(BankAccountMother::DEFAULT_ID, $events[0]->aggregateId());
    }
}
