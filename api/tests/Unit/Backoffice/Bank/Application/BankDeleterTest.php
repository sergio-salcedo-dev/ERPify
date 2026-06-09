<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Erpify\Backoffice\Bank\Application\BankDeleter;
use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Exception\BankInUseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankDeleter::class)]
#[CoversClass(BankInUseException::class)]
final class BankDeleterTest extends TestCase
{
    private const string BANK_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    public function testDeletesBankAndDispatchesDeletedEventWhenNoAccountsReferenceIt(): void
    {
        $bankRepository = new FakeBankRepository($this->makeBank());
        $messageBus = new RecordingMessageBus();
        $bankDeleter = $this->makeBankDeleter($bankRepository, accountCount: 0, messageBus: $messageBus);

        $bankDeleter->delete(self::BANK_ID);

        $this->assertTrue($bankRepository->removeCalled);
        $this->assertCount(1, $messageBus->dispatchedMessages);
        $this->assertInstanceOf(BankDeletedDomainEvent::class, $messageBus->dispatchedMessages[0]);
    }

    public function testThrowsBankInUseExceptionWhenAccountsReferenceTheBank(): void
    {
        $bankDeleter = $this->makeBankDeleter(
            new FakeBankRepository($this->makeBank()),
            accountCount: 3,
        );

        try {
            $bankDeleter->delete(self::BANK_ID);
            $this->fail('Expected BankInUseException to be thrown.');
        } catch (BankInUseException $bankInUseException) {
            $this->assertSame('bank-in-use', $bankInUseException->type());
            $this->assertSame(
                'Cannot delete the bank because it still has 3 associated accounts.',
                $bankInUseException->title(),
            );
            $this->assertSame(
                ['bankId' => self::BANK_ID, 'accountCount' => 3],
                $bankInUseException->context(),
            );
        }
    }

    public function testDispatchesNoEventAndRemovesNothingWhenDeletionIsRejected(): void
    {
        $bankRepository = new FakeBankRepository($this->makeBank());
        $messageBus = new RecordingMessageBus();
        $bankDeleter = $this->makeBankDeleter($bankRepository, accountCount: 1, messageBus: $messageBus);

        try {
            $bankDeleter->delete(self::BANK_ID);
        } catch (BankInUseException) {
            // Expected — the assertions below pin the no-mutation, no-dispatch contract.
        }

        $this->assertFalse($bankRepository->removeCalled);
        $this->assertSame([], $bankRepository->saved);
        $this->assertSame([], $messageBus->dispatchedMessages);
    }

    public function testMapsForeignKeyViolationOnRemoveToBankInUseException(): void
    {
        $bankRepository = new FakeBankRepository($this->makeBank(), removeFailure: $this->makeFkViolation());
        $messageBus = new RecordingMessageBus();
        // First count 0 (guard passes), recount 2 after the flush-time FK violation.
        $bankDeleter = $this->makeBankDeleter($bankRepository, accountCount: 0, messageBus: $messageBus, recount: 2);

        try {
            $bankDeleter->delete(self::BANK_ID);
            $this->fail('Expected BankInUseException to be thrown.');
        } catch (BankInUseException $bankInUseException) {
            $this->assertSame('bank-in-use', $bankInUseException->type());
            $this->assertSame(
                ['bankId' => self::BANK_ID, 'accountCount' => 2],
                $bankInUseException->context(),
            );
        }

        $this->assertSame([], $messageBus->dispatchedMessages);
    }

    public function testReportsAtLeastOneAccountWhenRecountAfterForeignKeyViolationIsZero(): void
    {
        $bankRepository = new FakeBankRepository($this->makeBank(), removeFailure: $this->makeFkViolation());
        // Reverse double-race: the violating account vanished again before the recount.
        // The FK violation itself proves >= 1 account existed at flush time.
        $bankDeleter = $this->makeBankDeleter($bankRepository, accountCount: 0, recount: 0);

        try {
            $bankDeleter->delete(self::BANK_ID);
            $this->fail('Expected BankInUseException to be thrown.');
        } catch (BankInUseException $bankInUseException) {
            $this->assertSame(
                ['bankId' => self::BANK_ID, 'accountCount' => 1],
                $bankInUseException->context(),
            );
        }
    }

    private function makeBankDeleter(
        FakeBankRepository $bankRepository,
        int $accountCount,
        ?RecordingMessageBus $messageBus = null,
        ?int $recount = null,
    ): BankDeleter {
        return new BankDeleter(
            $bankRepository,
            new BankFinder($bankRepository),
            new FakeBankAccountRepository($accountCount, $recount),
            $messageBus ?? new RecordingMessageBus(),
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

    private function makeBank(): Bank
    {
        $bank = Bank::create(self::BANK_ID, 'Acme Savings', 'ACME');
        // Drain the creation event so only a deletion can surface on the bus.
        $bank->pullDomainEvents();

        return $bank;
    }
}
