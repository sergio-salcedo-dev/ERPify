<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankCreator;
use Erpify\Backoffice\Bank\Application\Command\CreateBankCommand;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankCreator::class)]
final class BankCreatorTest extends TestCase
{
    use BankCollaboratorStubs;

    public function testCreatesBankAndPublishesTheCreatedEventInOneTransaction(): void
    {
        $bankRepository = new InMemoryBankRepository();
        $transactions = new ImmediateTransactionManager();
        $eventBus = new RecordingEventBus($transactions);

        $bank = $this->makeCreator($bankRepository, $eventBus, $transactions)->create(
            new CreateBankCommand('Acme Savings', 'ACME'),
        );

        $this->assertSame('Acme Savings', $bank->getName());
        $this->assertSame('ACME', $bank->getShortName());

        $this->assertSame([$bank], $bankRepository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankCreatedDomainEvent::class, $eventBus->publishedEvents[0]);
        // Where, not how many. A use case that publishes AFTER its unit of work returns opens exactly
        // one boundary too, so a count leaves the dual-write window free to reopen; this is the
        // observable that goes red when the publication moves outside the transaction.
        $this->assertSame([true], $eventBus->publishedInsideUnitOfWork);
    }

    private function makeCreator(
        InMemoryBankRepository $bankRepository,
        RecordingEventBus $eventBus,
        ImmediateTransactionManager $transactions,
    ): BankCreator {
        return new BankCreator(
            $bankRepository,
            $eventBus,
            $this->passThroughValidator(),
            $transactions,
        );
    }
}
