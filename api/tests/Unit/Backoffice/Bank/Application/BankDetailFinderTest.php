<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankAccountCountEnricher;
use Erpify\Backoffice\Bank\Application\BankDetailFinder;
use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Shared\Domain\Uuid\InvalidUuidException;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankDetailFinder::class)]
final class BankDetailFinderTest extends TestCase
{
    public function testEnrichesTheFoundBankWithItsAccountCount(): void
    {
        $finder = $this->makeFinder(BankMother::create(), [BankMother::DEFAULT_ID => 3]);

        $bank = $finder->find(BankMother::DEFAULT_ID);

        $this->assertSame(3, $bank->getAccountCount());
    }

    public function testReportsZeroWhenTheBankHasNoAccounts(): void
    {
        $finder = $this->makeFinder(BankMother::create(), []);

        $this->assertSame(0, $finder->find(BankMother::DEFAULT_ID)->getAccountCount());
    }

    public function testCountsByTheCanonicalAggregateIdNotTheRouteCasing(): void
    {
        // The count map is keyed by the DB-canonical (lower-case) id; an upper-case route id is a
        // valid UUID and still finds the bank, so the count must follow the aggregate, not the URL.
        $finder = $this->makeFinder(BankMother::create(), [BankMother::DEFAULT_ID => 7]);

        $this->assertSame(7, $finder->find(\strtoupper(BankMother::DEFAULT_ID))->getAccountCount());
    }

    public function testPropagatesNotFoundForAWellFormedButAbsentId(): void
    {
        $finder = $this->makeFinder(null, []);

        $this->expectException(BankNotFoundException::class);

        $finder->find(BankMother::DEFAULT_ID);
    }

    public function testRejectsAMalformedIdBeforeAnyLookup(): void
    {
        $finder = $this->makeFinder(BankMother::create(), []);

        $this->expectException(InvalidUuidException::class);

        $finder->find('not-a-uuid');
    }

    /**
     * @param array<string, int> $counts
     */
    private function makeFinder(?Bank $bank, array $counts): BankDetailFinder
    {
        return new BankDetailFinder(
            new BankFinder(new InMemoryBankRepository($bank)),
            new BankAccountCountEnricher(new InMemoryBankAccountCounter($counts)),
        );
    }
}
