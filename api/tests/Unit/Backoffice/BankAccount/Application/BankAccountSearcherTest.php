<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\BankAccount\Application\BankAccountSearcher;
use Erpify\Backoffice\BankAccount\Application\Query\SearchBankAccountsQuery;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(BankAccountSearcher::class)]
final class BankAccountSearcherTest extends TestCase
{
    private const string BANK_ID = BankAccountMother::DEFAULT_BANK_ID;

    public function testReturnsThePageAndRecordsExactlyOneActivityAccess(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $searcher = $this->makeSearcher($this->pageWithOneAccount(), $auditLogger);

        $page = $searcher->search(self::BANK_ID, new SearchBankAccountsQuery(new SearchCriteria()));

        $this->assertCount(1, $page->items);
        $this->assertCount(1, $auditLogger->records);

        $record = $auditLogger->records[0];
        $this->assertSame('BANK_ACCOUNTS_VIEWED', $record['action']);
        $this->assertSame(AuditLevel::ACTIVITY, $record['level']);
        $this->assertSame([], $record['metadata']);

        $resource = $record['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        $this->assertSame('Bank', $resource->type);
        $this->assertSame(self::BANK_ID, $resource->id);
    }

    public function testRecordsTheAccessEvenForAnEmptyPage(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $searcher = $this->makeSearcher($this->emptyPage(), $auditLogger);

        $searcher->search(self::BANK_ID, new SearchBankAccountsQuery(new SearchCriteria()));

        $this->assertCount(1, $auditLogger->records);
        $this->assertSame('BANK_ACCOUNTS_VIEWED', $auditLogger->records[0]['action']);
    }

    public function testRejectsAnAbsentBankWithoutSearchingOrAuditing(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $repository = new InMemoryBankAccountSearchRepository($this->emptyPage());
        $searcher = new BankAccountSearcher(
            new InMemoryBankExistenceChecker(BankNotFoundException::withId(self::BANK_ID)),
            $repository,
            $auditLogger,
        );

        try {
            $searcher->search(self::BANK_ID, new SearchBankAccountsQuery(new SearchCriteria()));
            $this->fail('Expected BankNotFoundException.');
        } catch (BankNotFoundException) {
            $this->assertFalse($repository->called, 'No account query runs when the bank is absent.');
            $this->assertSame([], $auditLogger->records, 'A 404 is not an auditable access.');
        }
    }

    public function testRejectsAMalformedBankIdBeforeAnyWork(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $repository = new InMemoryBankAccountSearchRepository($this->emptyPage());
        $searcher = new BankAccountSearcher(
            new InMemoryBankExistenceChecker(new InvalidUuidException()),
            $repository,
            $auditLogger,
        );

        $this->expectException(InvalidUuidException::class);

        try {
            $searcher->search('not-a-uuid', new SearchBankAccountsQuery(new SearchCriteria()));
        } finally {
            $this->assertFalse($repository->called);
            $this->assertSame([], $auditLogger->records);
        }
    }

    /**
     * @param Page<BankAccount> $page
     */
    private function makeSearcher(Page $page, RecordingAuditLogger $auditLogger): BankAccountSearcher
    {
        return new BankAccountSearcher(
            new InMemoryBankExistenceChecker(),
            new InMemoryBankAccountSearchRepository($page),
            $auditLogger,
        );
    }

    /**
     * @return Page<BankAccount>
     */
    private function pageWithOneAccount(): Page
    {
        return new Page(items: [BankAccountMother::create()], hasNext: false, hasPrev: false);
    }

    /**
     * @return Page<BankAccount>
     */
    private function emptyPage(): Page
    {
        return new Page(items: [], hasNext: false, hasPrev: false);
    }
}
