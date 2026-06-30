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
        $eventBus = new RecordingEventBus();

        $changed = $this->makeChanger($repository, $eventBus)->change(
            BankAccountMother::DEFAULT_ID,
            new ChangeBankAccountStatusCommand(BankAccountStatus::CLOSED),
        );

        $this->assertSame($account, $changed);
        $this->assertSame(BankAccountStatus::CLOSED, $changed->getStatus());
        $this->assertSame([$account], $repository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankAccountStatusChangedDomainEvent::class, $eventBus->publishedEvents[0]);
    }

    public function testARedundantTransitionToTheCurrentStatusPublishesNoEvent(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::ACTIVE);
        $repository = new InMemoryBankAccountRepository($account);
        $eventBus = new RecordingEventBus();

        $this->makeChanger($repository, $eventBus)->change(
            BankAccountMother::DEFAULT_ID,
            new ChangeBankAccountStatusCommand(BankAccountStatus::ACTIVE),
        );

        $this->assertSame([], $eventBus->publishedEvents);
    }

    private function makeChanger(
        InMemoryBankAccountRepository $repository,
        RecordingEventBus $eventBus,
    ): BankAccountStatusChanger {
        return new BankAccountStatusChanger(
            $repository,
            new BankAccountFinder($repository),
            $eventBus,
            $this->passThroughValidator(),
            $this->inlineTransactionEntityManager(),
        );
    }
}
