<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankCreator;
use Erpify\Backoffice\Bank\Application\Command\CreateBankCommand;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankCreator::class)]
final class BankCreatorTest extends TestCase
{
    use BankCollaboratorStubs;
    use InlineTransactionStubs;

    public function testCreatesBankAndPublishesTheCreatedEventInOneTransaction(): void
    {
        $bankRepository = new InMemoryBankRepository();
        $eventBus = new RecordingEventBus();

        $bank = $this->makeCreator($bankRepository, $eventBus)->create(
            new CreateBankCommand('Acme Savings', 'ACME'),
        );

        $this->assertSame('Acme Savings', $bank->getName());
        $this->assertSame('ACME', $bank->getShortName());

        $this->assertSame([$bank], $bankRepository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankCreatedDomainEvent::class, $eventBus->publishedEvents[0]);
    }

    private function makeCreator(
        InMemoryBankRepository $bankRepository,
        RecordingEventBus $eventBus,
    ): BankCreator {
        return new BankCreator(
            $bankRepository,
            $eventBus,
            $this->passThroughValidator(),
            $this->inlineTransactionEntityManager(),
        );
    }
}
