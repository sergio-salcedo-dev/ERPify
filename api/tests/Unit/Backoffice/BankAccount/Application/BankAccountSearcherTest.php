<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\BankAccount\Application\Audit\BankAccountsViewedAuditEvent;
use Erpify\Backoffice\BankAccount\Application\BankAccountSearcher;
use Erpify\Backoffice\BankAccount\Application\Query\SearchBankAccountsQuery;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Domain\Uuid\InvalidUuidException;
use Erpify\Shared\Infrastructure\Clock\SymfonyClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(BankAccountSearcher::class)]
final class BankAccountSearcherTest extends TestCase
{
    private const string BANK_ID = '11111111-1111-7000-8000-000000000001';

    private const string ACCOUNT_ID = '33333333-3333-7000-8000-000000000001';

    public function testReturnsThePageAndEmitsExactlyOneAccessAuditEvent(): void
    {
        $bus = new RecordingMessageBus();
        $searcher = $this->makeSearcher($this->pageWithOneAccount(), $bus);

        $page = $searcher->search(self::BANK_ID, new SearchBankAccountsQuery(new SearchCriteria()));

        $this->assertCount(1, $page->items);
        $this->assertCount(1, $bus->dispatchedMessages);
        $event = $bus->dispatchedMessages[0];
        $this->assertInstanceOf(BankAccountsViewedAuditEvent::class, $event);
        $this->assertSame(self::BANK_ID, $event->bankId);
    }

    public function testEmitsTheAccessEventEvenForAnEmptyPage(): void
    {
        $bus = new RecordingMessageBus();
        $searcher = $this->makeSearcher($this->emptyPage(), $bus);

        $searcher->search(self::BANK_ID, new SearchBankAccountsQuery(new SearchCriteria()));

        $this->assertCount(1, $bus->dispatchedMessages);
        $this->assertInstanceOf(BankAccountsViewedAuditEvent::class, $bus->dispatchedMessages[0]);
    }

    public function testAReadSurvivesAnAuditDispatchFailure(): void
    {
        // Access auditing is observability: a dispatch failure must not turn a good read into a 5xx.
        $searcher = new BankAccountSearcher(
            new InMemoryBankExistenceChecker(),
            new InMemoryBankAccountSearchRepository($this->pageWithOneAccount()),
            new ThrowingMessageBus(),
            new NullLogger(),
            new SymfonyClock(new MockClock()),
        );

        $page = $searcher->search(self::BANK_ID, new SearchBankAccountsQuery(new SearchCriteria()));

        $this->assertCount(1, $page->items);
    }

    public function testRejectsAnAbsentBankWithoutSearchingOrAuditing(): void
    {
        $bus = new RecordingMessageBus();
        $repository = new InMemoryBankAccountSearchRepository($this->emptyPage());
        $searcher = new BankAccountSearcher(
            new InMemoryBankExistenceChecker(BankNotFoundException::withId(self::BANK_ID)),
            $repository,
            $bus,
            new NullLogger(),
            new SymfonyClock(new MockClock()),
        );

        try {
            $searcher->search(self::BANK_ID, new SearchBankAccountsQuery(new SearchCriteria()));
            $this->fail('Expected BankNotFoundException.');
        } catch (BankNotFoundException) {
            $this->assertFalse($repository->called, 'No account query runs when the bank is absent.');
            $this->assertSame([], $bus->dispatchedMessages, 'A 404 is not an auditable access.');
        }
    }

    public function testRejectsAMalformedBankIdBeforeAnyWork(): void
    {
        $bus = new RecordingMessageBus();
        $repository = new InMemoryBankAccountSearchRepository($this->emptyPage());
        $searcher = new BankAccountSearcher(
            new InMemoryBankExistenceChecker(new InvalidUuidException()),
            $repository,
            $bus,
            new NullLogger(),
            new SymfonyClock(new MockClock()),
        );

        $this->expectException(InvalidUuidException::class);

        try {
            $searcher->search('not-a-uuid', new SearchBankAccountsQuery(new SearchCriteria()));
        } finally {
            $this->assertFalse($repository->called);
            $this->assertSame([], $bus->dispatchedMessages);
        }
    }

    /**
     * @param Page<BankAccount> $page
     */
    private function makeSearcher(Page $page, RecordingMessageBus $bus): BankAccountSearcher
    {
        return new BankAccountSearcher(
            new InMemoryBankExistenceChecker(),
            new InMemoryBankAccountSearchRepository($page),
            $bus,
            new NullLogger(),
            new SymfonyClock(new MockClock()),
        );
    }

    /**
     * @return Page<BankAccount>
     */
    private function pageWithOneAccount(): Page
    {
        $account = BankAccount::create(
            self::ACCOUNT_ID,
            self::BANK_ID,
            'Globex Corporation',
            'DE89370400440532013000',
        );

        return new Page(items: [$account], hasNext: false, hasPrev: false);
    }

    /**
     * @return Page<BankAccount>
     */
    private function emptyPage(): Page
    {
        return new Page(items: [], hasNext: false, hasPrev: false);
    }
}
