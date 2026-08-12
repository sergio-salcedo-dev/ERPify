<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankDeleter;
use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Exception\BankInUseException;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\FailingTransactionManager;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankDeleter::class)]
#[CoversClass(BankInUseException::class)]
final class BankDeleterTest extends TestCase
{
    public function testDeletesBankAndDispatchesDeletedEventWhenNoAccountsReferenceIt(): void
    {
        $bankRepository = new InMemoryBankRepository(BankMother::drained());
        $transactions = new ImmediateTransactionManager();
        $eventBus = new RecordingEventBus($transactions);
        $bankDeleter = $this->makeBankDeleter(
            $bankRepository,
            accountCount: 0,
            eventBus: $eventBus,
            transactions: $transactions,
        );

        $bankDeleter->delete(BankMother::DEFAULT_ID);

        $this->assertTrue($bankRepository->removeCalled);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankDeletedDomainEvent::class, $eventBus->publishedEvents[0]);
        // Where, not how many: publishing after the unit of work returns opens exactly one boundary too.
        $this->assertSame([true], $eventBus->publishedInsideUnitOfWork);
    }

    public function testThrowsBankInUseExceptionWhenAccountsReferenceTheBank(): void
    {
        $transactions = new ImmediateTransactionManager();
        $bankDeleter = $this->makeBankDeleter(
            new InMemoryBankRepository(BankMother::drained()),
            accountCount: 3,
            transactions: $transactions,
        );

        try {
            $bankDeleter->delete(BankMother::DEFAULT_ID);
            $this->fail('Expected BankInUseException to be thrown.');
        } catch (BankInUseException $bankInUseException) {
            $this->assertSame('bank-in-use', $bankInUseException->type());
            $this->assertSame(
                'Cannot delete the bank because it still has 3 associated accounts.',
                $bankInUseException->title(),
            );
            $this->assertSame(
                ['bankId' => BankMother::DEFAULT_ID, 'accountCount' => 3],
                $bankInUseException->context(),
            );
        }

        // The guard refuses before any boundary is opened: a use case that started the transaction and
        // then threw would produce an identical exception and an identical message.
        $this->assertSame(0, $transactions->opened);
    }

    public function testDispatchesNoEventAndRemovesNothingWhenDeletionIsRejected(): void
    {
        $bankRepository = new InMemoryBankRepository(BankMother::drained());
        $eventBus = new RecordingEventBus();
        $bankDeleter = $this->makeBankDeleter($bankRepository, accountCount: 1, eventBus: $eventBus);

        try {
            $bankDeleter->delete(BankMother::DEFAULT_ID);
        } catch (BankInUseException) {
            // Expected — the assertions below pin the no-mutation, no-dispatch contract.
        }

        $this->assertFalse($bankRepository->removeCalled);
        $this->assertSame([], $bankRepository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testMapsForeignKeyViolationOnRemoveToBankInUseException(): void
    {
        $bankRepository = new InMemoryBankRepository(BankMother::drained());
        $eventBus = new RecordingEventBus();
        // First count 0 (guard passes), recount 2 after the flush-time FK violation. The boundary fails at
        // the commit, so the removal and the publication have already been issued — the shape production
        // produces, and the one where "nothing survived" is a claim rather than a restatement.
        $transactions = FailingTransactionManager::referentialIntegrityAtCommit();
        $bankDeleter = $this->makeBankDeleter(
            $bankRepository,
            accountCount: 0,
            eventBus: $eventBus,
            recount: 2,
            transactions: $transactions,
        );

        try {
            $bankDeleter->delete(BankMother::DEFAULT_ID);
            $this->fail('Expected BankInUseException to be thrown.');
        } catch (BankInUseException $bankInUseException) {
            $this->assertSame('bank-in-use', $bankInUseException->type());
            $this->assertSame(
                ['bankId' => BankMother::DEFAULT_ID, 'accountCount' => 2],
                $bankInUseException->context(),
            );
        }

        // The callback ran and was abandoned: the removal was issued and the event published inside the
        // boundary, so what stops either from surviving is the rollback, not the use case declining to act.
        $this->assertTrue($bankRepository->removeCalled);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertSame(1, $transactions->opened);
    }

    public function testReportsAtLeastOneAccountWhenRecountAfterForeignKeyViolationIsZero(): void
    {
        $bankRepository = new InMemoryBankRepository(BankMother::drained());
        // Reverse double-race: the violating account vanished again before the recount.
        // The FK violation itself proves >= 1 account existed at flush time.
        $bankDeleter = $this->makeBankDeleter(
            $bankRepository,
            accountCount: 0,
            recount: 0,
            transactions: FailingTransactionManager::referentialIntegrityAtCommit(),
        );

        try {
            $bankDeleter->delete(BankMother::DEFAULT_ID);
            $this->fail('Expected BankInUseException to be thrown.');
        } catch (BankInUseException $bankInUseException) {
            $this->assertSame(
                ['bankId' => BankMother::DEFAULT_ID, 'accountCount' => 1],
                $bankInUseException->context(),
            );
        }
    }

    private function makeBankDeleter(
        InMemoryBankRepository $bankRepository,
        int $accountCount,
        ?RecordingEventBus $eventBus = null,
        ?int $recount = null,
        ?TransactionManager $transactions = null,
    ): BankDeleter {
        return new BankDeleter(
            $bankRepository,
            new BankFinder($bankRepository),
            new InMemoryBankAccountRepository($accountCount, $recount),
            $eventBus ?? new RecordingEventBus(),
            $transactions ?? new ImmediateTransactionManager(),
        );
    }
}
