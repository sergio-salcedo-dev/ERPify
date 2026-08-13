<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\BankAccountFinder;
use Erpify\Backoffice\BankAccount\Application\BankAccountUpdater;
use Erpify\Backoffice\BankAccount\Application\Command\UpdateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountUpdatedDomainEvent;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Tests\Unit\Backoffice\Bank\Application\RecordingEventBus;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountUpdater::class)]
final class BankAccountUpdaterTest extends TestCase
{
    use BankAccountWriteStubs;

    public function testUpdatesValidatesAndPublishesTheUpdatedEventInOneTransaction(): void
    {
        $account = BankAccountMother::drained();
        $repository = new InMemoryBankAccountRepository($account);
        $transactions = new ImmediateTransactionManager();
        $eventBus = new RecordingEventBus($transactions);

        $updated = $this->makeUpdater($repository, $eventBus, $transactions)->update(
            BankAccountMother::DEFAULT_ID,
            new UpdateBankAccountCommand(
                'Globex Renamed',
                'FR1420041010050500013M02606',
                null,
                'Ops',
                Currency::EUR,
            ),
        );

        $this->assertSame($account, $updated);
        $this->assertSame('Globex Renamed', $updated->getHolderName());
        $this->assertSame('FR1420041010050500013M02606', $updated->getIban());

        $this->assertSame([$account], $repository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankAccountUpdatedDomainEvent::class, $eventBus->publishedEvents[0]);
        // Where, not how many. A use case that publishes AFTER its unit of work returns opens exactly
        // one boundary too, so a count leaves the dual-write window free to reopen; this is the
        // observable that goes red when the publication moves outside the transaction.
        $this->assertSame([true], $eventBus->publishedInsideUnitOfWork);
    }

    public function testARedundantUpdateToTheStoredValuesPublishesNoEvent(): void
    {
        $account = BankAccountMother::drained();
        $repository = new InMemoryBankAccountRepository($account);
        $eventBus = new RecordingEventBus();
        $transactions = new ImmediateTransactionManager();

        $this->makeUpdater($repository, $eventBus, $transactions)->update(
            BankAccountMother::DEFAULT_ID,
            new UpdateBankAccountCommand(
                'Globex Corporation',
                'DE89370400440532013000',
                null,
                null,
                Currency::EUR,
            ),
        );

        $this->assertSame([], $eventBus->publishedEvents);
        // The idempotence lives in the aggregate, so the use case is untouched by it: it still opens
        // its boundary and saves an unchanged entity, exactly as the redundant status transition does.
        // This is the observable that would notice if the guard were ever hoisted up here.
        $this->assertSame([$account], $repository->saved);
        $this->assertSame(1, $transactions->opened);
    }

    private function makeUpdater(
        InMemoryBankAccountRepository $repository,
        RecordingEventBus $eventBus,
        ImmediateTransactionManager $transactions,
    ): BankAccountUpdater {
        return new BankAccountUpdater(
            $repository,
            new BankAccountFinder($repository),
            $eventBus,
            $this->passThroughValidator(),
            $transactions,
        );
    }
}
