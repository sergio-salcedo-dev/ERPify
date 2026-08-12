<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\BankAccountDeleter;
use Erpify\Backoffice\BankAccount\Application\BankAccountFinder;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountDeletedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotClosedException;
use Erpify\Tests\Unit\Backoffice\Bank\Application\RecordingEventBus;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountDeleter::class)]
#[CoversClass(BankAccountNotClosedException::class)]
final class BankAccountDeleterTest extends TestCase
{
    use BankAccountWriteStubs;

    public function testDeletesAccountAndDispatchesDeletedEventWhenClosed(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::CLOSED);
        $repository = new InMemoryBankAccountRepository($account);
        $transactions = new ImmediateTransactionManager();
        $eventBus = new RecordingEventBus($transactions);

        $this->makeDeleter($repository, $eventBus, $transactions)->delete(BankAccountMother::DEFAULT_ID);

        $this->assertTrue($repository->removeCalled);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankAccountDeletedDomainEvent::class, $eventBus->publishedEvents[0]);
        // Where, not how many. A use case that publishes AFTER its unit of work returns opens exactly
        // one boundary too, so a count leaves the dual-write window free to reopen; this is the
        // observable that goes red when the publication moves outside the transaction.
        $this->assertSame([true], $eventBus->publishedInsideUnitOfWork);
    }

    public function testThrowsNotClosedAndMutatesNothingWhenTheAccountIsNotClosed(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::ACTIVE);
        $repository = new InMemoryBankAccountRepository($account);
        $eventBus = new RecordingEventBus();

        try {
            $transactions = new ImmediateTransactionManager();
            $this->makeDeleter($repository, $eventBus, $transactions)->delete(BankAccountMother::DEFAULT_ID);
            $this->fail('Expected BankAccountNotClosedException to be thrown.');
        } catch (BankAccountNotClosedException $bankAccountNotClosedException) {
            $this->assertSame('bank-account-not-closed', $bankAccountNotClosedException->type());
            $this->assertSame(
                ['bankAccountId' => BankAccountMother::DEFAULT_ID],
                $bankAccountNotClosedException->context(),
            );
        }

        $this->assertFalse($repository->removeCalled);
        $this->assertSame([], $eventBus->publishedEvents);
        // As above: the refusal happens before any transaction is opened.
        $this->assertSame(0, $transactions->opened);
    }

    private function makeDeleter(
        InMemoryBankAccountRepository $repository,
        RecordingEventBus $eventBus,
        ImmediateTransactionManager $transactions,
    ): BankAccountDeleter {
        return new BankAccountDeleter(
            $repository,
            new BankAccountFinder($repository),
            $eventBus,
            $transactions,
        );
    }
}
