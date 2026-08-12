<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\BankAccount\Application\BankAccountCreator;
use Erpify\Backoffice\BankAccount\Application\Command\CreateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountCreatedDomainEvent;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Tests\Unit\Backoffice\Bank\Application\RecordingEventBus;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountCreator::class)]
final class BankAccountCreatorTest extends TestCase
{
    use BankAccountWriteStubs;

    public function testCreatesAccountAsActiveAndPublishesTheCreatedEventInOneTransaction(): void
    {
        $repository = new InMemoryBankAccountRepository();
        $transactions = new ImmediateTransactionManager();
        $eventBus = new RecordingEventBus($transactions);
        $existence = new InMemoryBankExistenceChecker();

        $account = $this->makeCreator($repository, $existence, $eventBus, $transactions)->create(
            new CreateBankAccountCommand(
                BankAccountMother::DEFAULT_BANK_ID,
                'Globex Corporation',
                'de89 3704 0044 0532 0130 00',
                'deutdeffxxx',
                'Treasury',
                Currency::EUR,
            ),
        );

        $this->assertTrue($existence->called);
        $this->assertSame(BankAccountMother::DEFAULT_BANK_ID, $account->getBankId());
        $this->assertSame('DE89370400440532013000', $account->getIban());
        $this->assertSame('DEUTDEFFXXX', $account->getBic());
        $this->assertSame(BankAccountStatus::ACTIVE, $account->getStatus());

        $this->assertSame([$account], $repository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankAccountCreatedDomainEvent::class, $eventBus->publishedEvents[0]);
        // Where, not how many. A use case that publishes AFTER its unit of work returns opens exactly
        // one boundary too, so a count leaves the dual-write window free to reopen; this is the
        // observable that goes red when the publication moves outside the transaction.
        $this->assertSame([true], $eventBus->publishedInsideUnitOfWork);
    }

    public function testRejectsCreationAndMutatesNothingWhenTheBankDoesNotExist(): void
    {
        $repository = new InMemoryBankAccountRepository();
        $eventBus = new RecordingEventBus();
        $existence = new InMemoryBankExistenceChecker(
            BankNotFoundException::withId(BankAccountMother::DEFAULT_BANK_ID),
        );

        try {
            $transactions = new ImmediateTransactionManager();
            $this->makeCreator($repository, $existence, $eventBus, $transactions)->create(
                new CreateBankAccountCommand(
                    BankAccountMother::DEFAULT_BANK_ID,
                    'Globex Corporation',
                    'DE89370400440532013000',
                ),
            );
            $this->fail('Expected BankNotFoundException to be thrown.');
        } catch (BankNotFoundException) {
            // Expected — the assertions below pin the no-write, no-dispatch contract.
        }

        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        // The guard refuses before the boundary: opening one and then throwing looks identical to a caller.
        $this->assertSame(0, $transactions->opened);
    }

    private function makeCreator(
        InMemoryBankAccountRepository $repository,
        InMemoryBankExistenceChecker $existence,
        RecordingEventBus $eventBus,
        ImmediateTransactionManager $transactions,
    ): BankAccountCreator {
        return new BankAccountCreator(
            $repository,
            $existence,
            $eventBus,
            $this->passThroughValidator(),
            $transactions,
        );
    }
}
