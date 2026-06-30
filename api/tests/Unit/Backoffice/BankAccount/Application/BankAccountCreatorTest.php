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
        $eventBus = new RecordingEventBus();
        $existence = new InMemoryBankExistenceChecker();

        $account = $this->makeCreator($repository, $existence, $eventBus)->create(
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
    }

    public function testRejectsCreationAndMutatesNothingWhenTheBankDoesNotExist(): void
    {
        $repository = new InMemoryBankAccountRepository();
        $eventBus = new RecordingEventBus();
        $existence = new InMemoryBankExistenceChecker(
            BankNotFoundException::withId(BankAccountMother::DEFAULT_BANK_ID),
        );

        try {
            $this->makeCreator($repository, $existence, $eventBus)->create(
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
    }

    private function makeCreator(
        InMemoryBankAccountRepository $repository,
        InMemoryBankExistenceChecker $existence,
        RecordingEventBus $eventBus,
    ): BankAccountCreator {
        return new BankAccountCreator(
            $repository,
            $existence,
            $eventBus,
            $this->passThroughValidator(),
            $this->inlineTransactionEntityManager(),
        );
    }
}
