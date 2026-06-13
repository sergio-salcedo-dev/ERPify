<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankDetailFinder;
use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Shared\Domain\Uuid\InvalidUuidException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankDetailFinder::class)]
final class BankDetailFinderTest extends TestCase
{
    private const string BANK_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    public function testEnrichesTheFoundBankWithItsAccountCount(): void
    {
        $finder = $this->makeFinder($this->bank(), [self::BANK_ID => 3]);

        $bank = $finder->find(self::BANK_ID);

        $this->assertSame(3, $bank->getAccountCount());
    }

    public function testReportsZeroWhenTheBankHasNoAccounts(): void
    {
        $finder = $this->makeFinder($this->bank(), []);

        $this->assertSame(0, $finder->find(self::BANK_ID)->getAccountCount());
    }

    public function testCountsByTheCanonicalAggregateIdNotTheRouteCasing(): void
    {
        // The count map is keyed by the DB-canonical (lower-case) id; an upper-case route id is a
        // valid UUID and still finds the bank, so the count must follow the aggregate, not the URL.
        $finder = $this->makeFinder($this->bank(), [self::BANK_ID => 7]);

        $this->assertSame(7, $finder->find(\strtoupper(self::BANK_ID))->getAccountCount());
    }

    public function testPropagatesNotFoundForAWellFormedButAbsentId(): void
    {
        $finder = $this->makeFinder(null, []);

        $this->expectException(BankNotFoundException::class);

        $finder->find(self::BANK_ID);
    }

    public function testRejectsAMalformedIdBeforeAnyLookup(): void
    {
        $finder = $this->makeFinder($this->bank(), []);

        $this->expectException(InvalidUuidException::class);

        $finder->find('not-a-uuid');
    }

    /**
     * @param array<string, int> $counts
     */
    private function makeFinder(?Bank $bank, array $counts): BankDetailFinder
    {
        return new BankDetailFinder(
            new BankFinder(new FakeBankRepository($bank)),
            new FakeAccountCountsByBank($counts),
        );
    }

    private function bank(): Bank
    {
        return Bank::create(self::BANK_ID, 'Acme Savings', 'ACME');
    }
}
