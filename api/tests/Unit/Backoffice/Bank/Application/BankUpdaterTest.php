<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Application\BankUpdater;
use Erpify\Backoffice\Bank\Application\Command\UpdateBankCommand;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankUpdater::class)]
final class BankUpdaterTest extends TestCase
{
    use BankCollaboratorStubs;
    use InlineTransactionStubs;

    public function testRenamesValidatesAndPublishesTheUpdatedEventInOneTransaction(): void
    {
        $bank = BankMother::drained();
        $bankRepository = new InMemoryBankRepository($bank);
        $eventBus = new RecordingEventBus();

        $updated = $this->makeUpdater($bankRepository, $eventBus)->update(
            BankMother::DEFAULT_ID,
            new UpdateBankCommand('Acme Renamed', 'ACMER'),
        );

        $this->assertSame($bank, $updated);
        $this->assertSame('Acme Renamed', $updated->getName());
        $this->assertSame('ACMER', $updated->getShortName());

        $this->assertSame([$bank], $bankRepository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankUpdatedDomainEvent::class, $eventBus->publishedEvents[0]);
    }

    private function makeUpdater(
        InMemoryBankRepository $bankRepository,
        RecordingEventBus $eventBus,
    ): BankUpdater {
        return new BankUpdater(
            $bankRepository,
            new BankFinder($bankRepository),
            $eventBus,
            $this->passThroughValidator(),
            $this->inlineTransactionEntityManager(),
        );
    }
}
