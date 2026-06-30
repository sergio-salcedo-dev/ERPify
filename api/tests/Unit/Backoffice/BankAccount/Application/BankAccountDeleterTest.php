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
        $eventBus = new RecordingEventBus();

        $this->makeDeleter($repository, $eventBus)->delete(BankAccountMother::DEFAULT_ID);

        $this->assertTrue($repository->removeCalled);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankAccountDeletedDomainEvent::class, $eventBus->publishedEvents[0]);
    }

    public function testThrowsNotClosedAndMutatesNothingWhenTheAccountIsNotClosed(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::ACTIVE);
        $repository = new InMemoryBankAccountRepository($account);
        $eventBus = new RecordingEventBus();

        try {
            $this->makeDeleter($repository, $eventBus)->delete(BankAccountMother::DEFAULT_ID);
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
    }

    private function makeDeleter(
        InMemoryBankAccountRepository $repository,
        RecordingEventBus $eventBus,
    ): BankAccountDeleter {
        return new BankAccountDeleter(
            $repository,
            new BankAccountFinder($repository),
            $eventBus,
            $this->inlineTransactionEntityManager(),
        );
    }
}
