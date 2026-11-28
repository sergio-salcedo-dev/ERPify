<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\BankAccountDeleter;
use Erpify\Backoffice\BankAccount\Application\Evidence\ReleaseBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountDeletedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountHasLiveEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotClosedException;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Backoffice\Bank\Application\RecordingEventBus;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence\FakeEvidenceDocuments;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence\InMemoryBankAccountEvidenceRepository;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the deleter, its doubles and the evidence it releases
 */
#[CoversClass(BankAccountDeleter::class)]
#[CoversClass(BankAccountNotClosedException::class)]
#[CoversClass(ReleaseBankAccountEvidence::class)]
final class BankAccountDeleterTest extends TestCase
{
    use BankAccountWriteStubs;

    private const string ERASED_DOCUMENT = '0197f2b4-2222-7000-8000-000000000001';

    private const string LIVE_DOCUMENT = '0197f2b4-2222-7000-8000-000000000002';

    private InMemoryBankAccountEvidenceRepository $evidence;

    private FakeEvidenceDocuments $documents;

    protected function setUp(): void
    {
        $this->evidence = new InMemoryBankAccountEvidenceRepository();
        $this->documents = new FakeEvidenceDocuments();
    }

    public function testDeletesAccountAndDispatchesDeletedEventWhenClosed(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::CLOSED);
        $repository = new InMemoryBankAccountRepository($account);
        $transactions = new ImmediateTransactionManager();
        $eventBus = new RecordingEventBus($transactions);

        $this->makeDeleter($repository, $eventBus, $transactions)->delete(BankAccountMother::DEFAULT_ID);

        $this->assertTrue($repository->removeCalled);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankAccountDeletedDomainEvent::class, $eventBus->publishedEvents[0]);
        // Where, not how many. A use case that publishes AFTER its unit of work returns opens exactly
        // one boundary too, so a count leaves the dual-write window free to reopen; this is the
        // observable that goes red when the publication moves outside the transaction.
        $this->assertSame([true], $eventBus->publishedInsideUnitOfWork);
        $this->assertSame(0, $repository->plainReads, 'nothing is decided on a row read without the lock');
        $this->assertSame(1, $repository->lockedReads);
    }

    public function testThrowsNotClosedAndMutatesNothingWhenTheAccountIsNotClosed(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::ACTIVE);
        $repository = new InMemoryBankAccountRepository($account);
        $eventBus = new RecordingEventBus();

        try {
            $transactions = new ImmediateTransactionManager();
            $this->makeDeleter($repository, $eventBus, $transactions)->delete(BankAccountMother::DEFAULT_ID);
            $this->fail('Expected BankAccountNotClosedException to be thrown.');
        } catch (BankAccountNotClosedException $bankAccountNotClosedException) {
            $this->assertSame('bank-account-not-closed', $bankAccountNotClosedException->type());
            $this->assertSame(
                ['bankAccountId' => BankAccountMother::DEFAULT_ID],
                $bankAccountNotClosedException->context(),
            );
        }

        $this->assertFalse($repository->removeCalled);
        $this->assertSame([], $eventBus->publishedEvents);
        // The precondition is judged under the lock, on the locked instance, and the refusal rolls that unit back.
        $this->assertSame(1, $transactions->abandoned);
        $this->assertSame(0, $transactions->committed);
    }

    public function testRemovesTheLinksOfEvidenceWhoseDocumentsAreAllErasedWithTheAccount(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::CLOSED);
        $repository = new InMemoryBankAccountRepository($account);
        $transactions = new ImmediateTransactionManager();
        $this->documents->file(self::ERASED_DOCUMENT, 'erased');
        $this->evidence->add($this->link(self::ERASED_DOCUMENT));

        $deleter = $this->makeDeleter($repository, new RecordingEventBus($transactions), $transactions);
        $deleter->delete(BankAccountMother::DEFAULT_ID);

        $this->assertTrue($repository->removeCalled);
        $this->assertCount(1, $this->evidence->removed);
        $this->assertSame([], $this->evidence->links);
    }

    public function testRefusesWhileALinkedDocumentIsNotErasedAndRemovesNothing(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::CLOSED);
        $repository = new InMemoryBankAccountRepository($account);
        $transactions = new ImmediateTransactionManager();
        $eventBus = new RecordingEventBus($transactions);
        $this->documents->file(self::LIVE_DOCUMENT, 'ready');
        $this->evidence->add($this->link(self::LIVE_DOCUMENT));

        try {
            $this->makeDeleter($repository, $eventBus, $transactions)->delete(BankAccountMother::DEFAULT_ID);
            $this->fail('Expected BankAccountHasLiveEvidence to be thrown.');
        } catch (BankAccountHasLiveEvidence $bankAccountHasLiveEvidence) {
            $this->assertSame('bank-account-has-live-evidence', $bankAccountHasLiveEvidence->type());
        }

        $this->assertFalse($repository->removeCalled);
        $this->assertSame([], $this->evidence->removed);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    private function link(string $documentId): BankAccountEvidence
    {
        return BankAccountEvidence::attach(
            Uuid::generate(),
            BankAccountMother::DEFAULT_ID,
            $documentId,
            BankAccountEvidenceKind::OwnershipCertificate,
        );
    }

    private function makeDeleter(
        InMemoryBankAccountRepository $repository,
        RecordingEventBus $eventBus,
        ImmediateTransactionManager $transactions,
    ): BankAccountDeleter {
        return new BankAccountDeleter(
            $repository,
            $eventBus,
            $transactions,
            new ReleaseBankAccountEvidence($this->evidence, $this->documents),
        );
    }
}
