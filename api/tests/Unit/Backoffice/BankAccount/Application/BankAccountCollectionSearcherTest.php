<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Application\BankAccountCollectionSearcher;
use Erpify\Backoffice\BankAccount\Application\Query\SearchBankAccountsQuery;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(BankAccountCollectionSearcher::class)]
final class BankAccountCollectionSearcherTest extends TestCase
{
    public function testReturnsThePageAndRecordsExactlyOneCollectionWideActivitySearch(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $repository = new InMemoryBankAccountCollectionSearchRepository($this->pageWithOneRow());
        $searcher = new BankAccountCollectionSearcher($repository, $auditLogger);

        $page = $searcher->search(new SearchBankAccountsQuery(new SearchCriteria()));

        $this->assertTrue($repository->called);
        $this->assertCount(1, $page->items);
        $this->assertCount(1, $auditLogger->records);

        $record = $auditLogger->records[0];
        $this->assertSame('BANK_ACCOUNTS_SEARCHED', $record['action']);
        $this->assertSame(AuditLevel::ACTIVITY, $record['level']);
        $this->assertNotInstanceOf(
            AuditResource::class,
            $record['resource'],
            'A collection-wide search pins no single resource.',
        );
        $this->assertSame([], $record['metadata']);
    }

    public function testRecordsTheSearchEvenForAnEmptyPage(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $searcher = new BankAccountCollectionSearcher(
            new InMemoryBankAccountCollectionSearchRepository($this->emptyPage()),
            $auditLogger,
        );

        $page = $searcher->search(new SearchBankAccountsQuery(new SearchCriteria()));

        $this->assertSame([], $page->items);
        $this->assertCount(1, $auditLogger->records);

        $record = $auditLogger->records[0];
        $this->assertSame('BANK_ACCOUNTS_SEARCHED', $record['action']);
        $this->assertSame(AuditLevel::ACTIVITY, $record['level']);
        $this->assertNotInstanceOf(AuditResource::class, $record['resource']);
    }

    /**
     * @return Page<BankAccountCollectionRow>
     */
    private function pageWithOneRow(): Page
    {
        return new Page(items: [$this->row()], hasNext: false, hasPrev: false);
    }

    /**
     * @return Page<BankAccountCollectionRow>
     */
    private function emptyPage(): Page
    {
        return new Page(items: [], hasNext: false, hasPrev: false);
    }

    private function row(): BankAccountCollectionRow
    {
        return new BankAccountCollectionRow(
            '33333333-3333-7000-8000-000000000001',
            '11111111-1111-7000-8000-000000000001',
            'JPMorgan Chase',
            'JPM',
            'Globex Corporation',
            'DE89370400440532013000',
            'DEUTDEFFXXX',
            'Globex Treasury',
            Currency::EUR,
            BankAccountStatus::INACTIVE,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
        );
    }
}
