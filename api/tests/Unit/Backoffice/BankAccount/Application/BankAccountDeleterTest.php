<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\BankAccountDeleter;
use Erpify\Backoffice\BankAccount\Application\Evidence\LiveEvidenceGuard;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Enum\EvidenceKind;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountDeletedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountHasLiveEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotClosedException;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Tests\Unit\Backoffice\Bank\Application\RecordingEventBus;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountDeleter::class)]
#[CoversClass(BankAccountNotClosedException::class)]
#[CoversClass(LiveEvidenceGuard::class)]
#[CoversClass(BankAccountHasLiveEvidence::class)]
final class BankAccountDeleterTest extends TestCase
{
    use BankAccountWriteStubs;

    private const string LINK_A = '0196f0a0-0000-7000-8000-00000000a001';

    private const string LINK_B = '0196f0a0-0000-7000-8000-00000000a002';

    private const string DOCUMENT_A = '0196f0a0-0000-7000-8000-00000000d001';

    private const string DOCUMENT_B = '0196f0a0-0000-7000-8000-00000000d002';

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
    }

    public function testRefusesWhileALinkedDocumentIsNotErasedAndRemovesNothing(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::CLOSED);
        $repository = new InMemoryBankAccountRepository($account);
        $evidence = new InMemoryBankAccountEvidenceRepository(
            BankAccountEvidence::attach(self::LINK_A, BankAccountMother::DEFAULT_ID, self::DOCUMENT_A, EvidenceKind::OwnershipCertificate),
            BankAccountEvidence::attach(self::LINK_B, BankAccountMother::DEFAULT_ID, self::DOCUMENT_B, EvidenceKind::OwnershipCertificate),
        );
        $documents = (new EvidenceDocumentsDouble())
            ->withDocument(self::DOCUMENT_A, DocumentState::Erased)
            ->withDocument(self::DOCUMENT_B, DocumentState::Ready)
        ;
        $eventBus = new RecordingEventBus();

        try {
            $this->makeDeleter($repository, $eventBus, new ImmediateTransactionManager(), $evidence, $documents)
                ->delete(BankAccountMother::DEFAULT_ID)
            ;
            $this->fail('Expected BankAccountHasLiveEvidence to be thrown.');
        } catch (BankAccountHasLiveEvidence $bankAccountHasLiveEvidence) {
            $this->assertSame('bank-account-has-live-evidence', $bankAccountHasLiveEvidence->type());
            // Counts, never names: one of the two documents is still live.
            $this->assertSame(['liveDocuments' => 1], $bankAccountHasLiveEvidence->context());
        }

        $this->assertFalse($repository->removeCalled);
        $this->assertSame([], $evidence->removed);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testReleasesTheLinksBeforeRemovingTheAccountOnceEveryDocumentIsErased(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::CLOSED);
        $repository = new InMemoryBankAccountRepository($account);
        $link = BankAccountEvidence::attach(self::LINK_A, BankAccountMother::DEFAULT_ID, self::DOCUMENT_A, EvidenceKind::OwnershipCertificate);
        $evidence = new InMemoryBankAccountEvidenceRepository($link);
        $documents = (new EvidenceDocumentsDouble())->withDocument(self::DOCUMENT_A, DocumentState::Erased);
        $transactions = new ImmediateTransactionManager();

        $this->makeDeleter($repository, new RecordingEventBus($transactions), $transactions, $evidence, $documents)
            ->delete(BankAccountMother::DEFAULT_ID)
        ;

        $this->assertSame([$link], $evidence->removed);
        $this->assertTrue($repository->removeCalled);
    }

    public function testAnswersNotFoundForAnAccountThatDoesNotExist(): void
    {
        $this->expectException(BankAccountNotFoundException::class);

        $this->makeDeleter(new InMemoryBankAccountRepository(), new RecordingEventBus(), new ImmediateTransactionManager())
            ->delete(BankAccountMother::DEFAULT_ID)
        ;
    }

    private function makeDeleter(
        InMemoryBankAccountRepository $repository,
        RecordingEventBus $eventBus,
        ImmediateTransactionManager $transactions,
        InMemoryBankAccountEvidenceRepository $evidence = new InMemoryBankAccountEvidenceRepository(),
        EvidenceDocumentsDouble $documents = new EvidenceDocumentsDouble(),
    ): BankAccountDeleter {
        return new BankAccountDeleter(
            $repository,
            new LiveEvidenceGuard($evidence, $documents->seam()),
            $eventBus,
            $transactions,
        );
    }
}
