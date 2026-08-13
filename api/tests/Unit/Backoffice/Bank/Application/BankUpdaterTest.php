<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Application\BankUpdater;
use Erpify\Backoffice\Bank\Application\Command\UpdateBankCommand;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankUpdater::class)]
final class BankUpdaterTest extends TestCase
{
    use BankCollaboratorStubs;

    public function testRenamesValidatesAndPublishesTheUpdatedEventInOneTransaction(): void
    {
        $bank = BankMother::drained();
        $bankRepository = new InMemoryBankRepository($bank);
        $transactions = new ImmediateTransactionManager();
        $eventBus = new RecordingEventBus($transactions);

        $updated = $this->makeUpdater($bankRepository, $eventBus, $transactions)->update(
            BankMother::DEFAULT_ID,
            new UpdateBankCommand('Acme Renamed', 'ACMER'),
        );

        $this->assertSame($bank, $updated);
        $this->assertSame('Acme Renamed', $updated->getName());
        $this->assertSame('ACMER', $updated->getShortName());

        $this->assertSame([$bank], $bankRepository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankUpdatedDomainEvent::class, $eventBus->publishedEvents[0]);
        // Where, not how many. A use case that publishes AFTER its unit of work returns opens exactly
        // one boundary too, so a count leaves the dual-write window free to reopen; this is the
        // observable that goes red when the publication moves outside the transaction.
        $this->assertSame([true], $eventBus->publishedInsideUnitOfWork);
    }

    public function testARedundantRenameToTheStoredValuesPublishesNoEvent(): void
    {
        $bank = BankMother::drained();
        $bankRepository = new InMemoryBankRepository($bank);
        $eventBus = new RecordingEventBus();
        $transactions = new ImmediateTransactionManager();

        $this->makeUpdater($bankRepository, $eventBus, $transactions)->update(
            BankMother::DEFAULT_ID,
            new UpdateBankCommand('Acme Savings', 'ACME'),
        );

        $this->assertSame([], $eventBus->publishedEvents);
        // The idempotence lives in the aggregate, so the use case is untouched by it: it still opens
        // its boundary and saves an unchanged entity, exactly as the redundant status transition does.
        // This is the observable that would notice if the guard were ever hoisted up here.
        $this->assertSame([$bank], $bankRepository->saved);
        $this->assertSame(1, $transactions->opened);
    }

    private function makeUpdater(
        InMemoryBankRepository $bankRepository,
        RecordingEventBus $eventBus,
        ImmediateTransactionManager $transactions,
    ): BankUpdater {
        return new BankUpdater(
            $bankRepository,
            new BankFinder($bankRepository),
            $eventBus,
            $this->passThroughValidator(),
            $transactions,
        );
    }
}
