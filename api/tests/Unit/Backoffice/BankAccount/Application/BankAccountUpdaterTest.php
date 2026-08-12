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
        $eventBus = new RecordingEventBus();

        $transactions = new ImmediateTransactionManager();
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
        // The write and its event share one boundary: published outside it, every assertion
        // above still passes while the dual-write window this use case closes is reopened.
        $this->assertSame(1, $transactions->committed);
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
