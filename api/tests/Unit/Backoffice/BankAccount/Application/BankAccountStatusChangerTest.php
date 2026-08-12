<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\BankAccountFinder;
use Erpify\Backoffice\BankAccount\Application\BankAccountStatusChanger;
use Erpify\Backoffice\BankAccount\Application\Command\ChangeBankAccountStatusCommand;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountStatusChangedDomainEvent;
use Erpify\Tests\Unit\Backoffice\Bank\Application\RecordingEventBus;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountStatusChanger::class)]
final class BankAccountStatusChangerTest extends TestCase
{
    use BankAccountWriteStubs;

    public function testChangesStatusValidatesAndPublishesTheStatusChangedEventInOneTransaction(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::ACTIVE);
        $repository = new InMemoryBankAccountRepository($account);
        $transactions = new ImmediateTransactionManager();
        $eventBus = new RecordingEventBus($transactions);

        $changed = $this->makeChanger($repository, $eventBus, $transactions)->change(
            BankAccountMother::DEFAULT_ID,
            new ChangeBankAccountStatusCommand(BankAccountStatus::CLOSED),
        );

        $this->assertSame($account, $changed);
        $this->assertSame(BankAccountStatus::CLOSED, $changed->getStatus());
        $this->assertSame([$account], $repository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankAccountStatusChangedDomainEvent::class, $eventBus->publishedEvents[0]);
        // Where, not how many. A use case that publishes AFTER its unit of work returns opens exactly
        // one boundary too, so a count leaves the dual-write window free to reopen; this is the
        // observable that goes red when the publication moves outside the transaction.
        $this->assertSame([true], $eventBus->publishedInsideUnitOfWork);
    }

    public function testARedundantTransitionToTheCurrentStatusPublishesNoEvent(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::ACTIVE);
        $repository = new InMemoryBankAccountRepository($account);
        $eventBus = new RecordingEventBus();

        $transactions = new ImmediateTransactionManager();
        $this->makeChanger($repository, $eventBus, $transactions)->change(
            BankAccountMother::DEFAULT_ID,
            new ChangeBankAccountStatusCommand(BankAccountStatus::ACTIVE),
        );

        $this->assertSame([], $eventBus->publishedEvents);
        // Measured, and worth pinning precisely because it is the opposite of what the shape suggests: a
        // redundant transition still opens a boundary and commits nothing inside it. Cheap today (an empty
        // transaction), but it is the kind of cost that only shows up under load, and this assertion is what
        // would notice if the guard ever moved in front of it.
        $this->assertSame(1, $transactions->opened);
    }

    private function makeChanger(
        InMemoryBankAccountRepository $repository,
        RecordingEventBus $eventBus,
        ImmediateTransactionManager $transactions,
    ): BankAccountStatusChanger {
        return new BankAccountStatusChanger(
            $repository,
            new BankAccountFinder($repository),
            $eventBus,
            $this->passThroughValidator(),
            $transactions,
        );
    }
}
