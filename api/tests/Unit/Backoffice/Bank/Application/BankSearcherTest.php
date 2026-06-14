<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankAccountCountEnricher;
use Erpify\Backoffice\Bank\Application\BankSearcher;
use Erpify\Backoffice\Bank\Application\Query\SearchBanksQuery;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\SearchCriteria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankSearcher::class)]
final class BankSearcherTest extends TestCase
{
    private const string BANK_A = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a01';

    private const string BANK_B = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a02';

    private const string BANK_C = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a03';

    public function testEnrichesEachBankWithItsAccountCountAndZeroWhenAbsent(): void
    {
        $page = $this->pageOf(
            Bank::create(self::BANK_A, 'Bank A', 'BA'),
            Bank::create(self::BANK_B, 'Bank B', 'BB'),
            Bank::create(self::BANK_C, 'Bank C', 'BC'),
        );
        $accountCounts = new InMemoryBankAccountCounter([self::BANK_A => 12, self::BANK_B => 3]);
        $searcher = new BankSearcher(
            new InMemoryBankSearchRepository($page),
            new BankAccountCountEnricher($accountCounts),
        );

        $result = $searcher->search(new SearchBanksQuery(new SearchCriteria()));

        $counts = [];

        foreach ($result->items as $bank) {
            $id = $bank->getId();
            $this->assertNotNull($id);
            $counts[$id] = $bank->getAccountCount();
        }

        // A bank with no accounts (BANK_C, absent from the map) defaults to 0.
        $this->assertSame([self::BANK_A => 12, self::BANK_B => 3, self::BANK_C => 0], $counts);
    }

    public function testResolvesAllPageIdsInASingleBatchedCall(): void
    {
        $page = $this->pageOf(
            Bank::create(self::BANK_A, 'Bank A', 'BA'),
            Bank::create(self::BANK_B, 'Bank B', 'BB'),
        );
        $accountCounts = new InMemoryBankAccountCounter([self::BANK_A => 1, self::BANK_B => 2]);
        $searcher = new BankSearcher(
            new InMemoryBankSearchRepository($page),
            new BankAccountCountEnricher($accountCounts),
        );

        $searcher->search(new SearchBanksQuery(new SearchCriteria()));

        $this->assertSame(
            [[self::BANK_A, self::BANK_B]],
            $accountCounts->receivedBankIds,
            'All page ids are resolved in one batched call — not one call per row.',
        );
    }

    public function testEmptyPageEnrichesNothingWithoutFailing(): void
    {
        $accountCounts = new InMemoryBankAccountCounter();
        $searcher = new BankSearcher(
            new InMemoryBankSearchRepository($this->pageOf()),
            new BankAccountCountEnricher($accountCounts),
        );

        $result = $searcher->search(new SearchBanksQuery(new SearchCriteria()));

        $this->assertSame([], $result->items);
        $this->assertSame([[]], $accountCounts->receivedBankIds, 'An empty page yields an empty id batch.');
    }

    /**
     * @return Page<Bank>
     */
    private function pageOf(Bank ...$banks): Page
    {
        return new Page(items: \array_values($banks), hasNext: false, hasPrev: false);
    }
}
