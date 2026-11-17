<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankDeleter;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Exception\BankInUseException;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What deleting a bank does with the logo it holds: the id read from the instance reloaded UNDER the lock, released in
 * the unit of work that removes the row, and taken back with it when that unit is rolled back.
 *
 * @internal
 */
#[CoversClass(BankDeleter::class)]
final class BankDeleterLogoReleaseTest extends TestCase
{
    private const string LOGO = '0190b1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';

    public function testReleasesTheLogoTheLockedBankHeldInTheSameUnitOfWorkAsTheRemoval(): void
    {
        $logo = self::LOGO;
        $bankRepository = new InMemoryBankRepository(BankMother::withLogo($logo));
        $transactions = new ImmediateTransactionManager();
        $eventBus = new RecordingEventBus($transactions);
        $bankDeleter = BankDeleters::over($bankRepository, 0, $eventBus, $transactions);

        $bankDeleter->delete(BankMother::DEFAULT_ID);

        $this->assertTrue($bankRepository->removeCalled);
        $this->assertSame(1, $bankRepository->lockedLoads, 'the bank is reloaded once, under its lock');
        $this->assertCount(2, $eventBus->publishedEvents);
        $this->assertInstanceOf(ImageDeletionRequested::class, $eventBus->publishedEvents[0]);
        $this->assertSame($logo, $eventBus->publishedEvents[0]->aggregateId());
        $this->assertInstanceOf(BankDeletedDomainEvent::class, $eventBus->publishedEvents[1]);
        $this->assertSame([true, true], $eventBus->publishedInsideUnitOfWork);
    }

    public function testQueuesNoReleaseForABankWithoutALogo(): void
    {
        $eventBus = new RecordingEventBus();
        $bankDeleter = BankDeleters::over(
            new InMemoryBankRepository(BankMother::drained()),
            0,
            $eventBus,
            new ImmediateTransactionManager(),
        );

        $bankDeleter->delete(BankMother::DEFAULT_ID);

        $this->assertContainsOnlyInstancesOf(BankDeletedDomainEvent::class, $eventBus->publishedEvents);
    }

    /**
     * A bank that still has accounts is refused before any unit of work opens, whatever it holds: its logo is neither
     * released nor forgotten, and no request to destroy it is queued.
     */
    public function testABankWithAccountsKeepsItsLogoAndReleasesNothing(): void
    {
        $bank = BankMother::withLogo(self::LOGO);
        $bankRepository = new InMemoryBankRepository($bank);
        $transactions = new ImmediateTransactionManager();
        $eventBus = new RecordingEventBus($transactions);
        $bankDeleter = BankDeleters::over($bankRepository, 3, $eventBus, $transactions);

        try {
            $bankDeleter->delete(BankMother::DEFAULT_ID);
            $this->fail('Expected BankInUseException to be thrown.');
        } catch (BankInUseException $bankInUseException) {
            $this->assertSame('bank-in-use', $bankInUseException->type());
        }

        $this->assertSame(0, $transactions->opened, 'refused before any unit of work');
        $this->assertSame(0, $bankRepository->lockedLoads);
        $this->assertFalse($bankRepository->removeCalled);
        $this->assertSame([], $eventBus->publishedEvents, 'no release and no deleted event');
        $this->assertSame(self::LOGO, $bank->getLogoImageId(), 'the logo is still held');
    }

    /**
     * The bank is there for the first, unlocked read and gone by the time the lock is asked for: the delete answers
     * the bank's own 404 and neither removes nor publishes nor releases anything.
     */
    public function testABankThatVanishesBetweenTheFirstReadAndTheLockedReloadIsNotFoundAndNothingIsReleased(): void
    {
        $bankRepository = new InMemoryBankRepository(BankMother::withLogo(self::LOGO));
        $bankRepository->vanishesBeforeTheLock = true;

        $eventBus = new RecordingEventBus();
        $bankDeleter = BankDeleters::over($bankRepository, 0, $eventBus, new ImmediateTransactionManager());

        try {
            $bankDeleter->delete(BankMother::DEFAULT_ID);
            $this->fail('Expected BankNotFoundException to be thrown.');
        } catch (BankNotFoundException $bankNotFoundException) {
            $this->assertSame('bank-not-found', $bankNotFoundException->type());
        }

        $this->assertFalse($bankRepository->removeCalled);
        $this->assertSame([], $eventBus->publishedEvents);
    }
}
