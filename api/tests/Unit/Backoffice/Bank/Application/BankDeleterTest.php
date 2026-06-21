<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Erpify\Backoffice\Bank\Application\BankDeleter;
use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Exception\BankInUseException;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankDeleter::class)]
#[CoversClass(BankInUseException::class)]
final class BankDeleterTest extends TestCase
{
    use InlineTransactionStubs;

    public function testDeletesBankAndDispatchesDeletedEventWhenNoAccountsReferenceIt(): void
    {
        $bankRepository = new InMemoryBankRepository(BankMother::drained());
        $eventBus = new RecordingEventBus();
        $bankDeleter = $this->makeBankDeleter($bankRepository, accountCount: 0, eventBus: $eventBus);

        $bankDeleter->delete(BankMother::DEFAULT_ID);

        $this->assertTrue($bankRepository->removeCalled);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankDeletedDomainEvent::class, $eventBus->publishedEvents[0]);
    }

    public function testThrowsBankInUseExceptionWhenAccountsReferenceTheBank(): void
    {
        $bankDeleter = $this->makeBankDeleter(
            new InMemoryBankRepository(BankMother::drained()),
            accountCount: 3,
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
        $bankRepository = new InMemoryBankRepository(BankMother::drained(), removeFailure: $this->makeFkViolation());
        $eventBus = new RecordingEventBus();
        // First count 0 (guard passes), recount 2 after the flush-time FK violation.
        $bankDeleter = $this->makeBankDeleter($bankRepository, accountCount: 0, eventBus: $eventBus, recount: 2);

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

        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testReportsAtLeastOneAccountWhenRecountAfterForeignKeyViolationIsZero(): void
    {
        $bankRepository = new InMemoryBankRepository(BankMother::drained(), removeFailure: $this->makeFkViolation());
        // Reverse double-race: the violating account vanished again before the recount.
        // The FK violation itself proves >= 1 account existed at flush time.
        $bankDeleter = $this->makeBankDeleter($bankRepository, accountCount: 0, recount: 0);

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
    ): BankDeleter {
        return new BankDeleter(
            $bankRepository,
            new BankFinder($bankRepository),
            new InMemoryBankAccountRepository($accountCount, $recount),
            $eventBus ?? new RecordingEventBus(),
            $this->inlineTransactionEntityManager(),
            $this->noopManagerRegistry(),
        );
    }

    private function makeFkViolation(): ForeignKeyConstraintViolationException
    {
        // SQLSTATE 23503 = Postgres foreign_key_violation, as raised by the bank_account FK.
        return new ForeignKeyConstraintViolationException(
            new StubDriverException('violates foreign key constraint "fk_53a23e0a11c8fb41"', '23503'),
            null,
        );
    }
}
