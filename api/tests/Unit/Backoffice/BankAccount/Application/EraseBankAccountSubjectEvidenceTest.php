<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject;
use Erpify\Backoffice\BankAccount\Application\Evidence\ReleaseBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountHasLiveEvidence;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Domain\KeyState;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence\FakeEvidenceDocuments;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence\InMemoryBankAccountEvidenceRepository;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A subject's erasure over an account that holds evidence: a document still live refuses the whole erasure before
 * anything irreversible, and with every document erased the links go first, then the account, then the key.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the eraser, its doubles and the evidence it releases
 */
#[CoversClass(EraseBankAccountSubject::class)]
#[CoversClass(ReleaseBankAccountEvidence::class)]
final class EraseBankAccountSubjectEvidenceTest extends TestCase
{
    private const string LIVE = '0197f2b4-2222-7000-8000-000000000001';

    private const string ERASED = '0197f2b4-2222-7000-8000-000000000002';

    private InMemoryBankAccountEvidenceRepository $evidence;

    private FakeEvidenceDocuments $documents;

    protected function setUp(): void
    {
        $this->evidence = new InMemoryBankAccountEvidenceRepository();
        $this->documents = new FakeEvidenceDocuments();
        $this->documents->file(self::LIVE, 'ready');
        $this->documents->file(self::ERASED, 'erased');
    }

    #[Test]
    public function aLiveLinkedDocumentRefusesAndNothingIrreversibleIsCalled(): void
    {
        $this->link(self::ERASED);
        $this->link(self::LIVE);
        $accounts = new InMemoryBankAccountRepository(BankAccountMother::create());
        $keystore = $this->createMock(Keystore::class);
        $keystore->expects($this->never())->method('destroy');
        $audit = new RecordingAuditLogger();

        try {
            $this->eraser($accounts, $keystore, $audit)->execute(BankAccountMother::DEFAULT_ID);
            $this->fail('Expected BankAccountHasLiveEvidence.');
        } catch (BankAccountHasLiveEvidence $bankAccountHasLiveEvidence) {
            $this->assertSame('bank-account-has-live-evidence', $bankAccountHasLiveEvidence->type());
        }

        $this->assertFalse($accounts->removeCalled, 'the account record survives');
        $this->assertSame([], $this->evidence->removed);
        $this->assertSame([], $audit->records, 'no GDPR_SUBJECT_ERASED evidence row');
    }

    #[Test]
    public function withEveryLinkedDocumentErasedTheLinksGoThenTheAccountThenTheKey(): void
    {
        $this->link(self::ERASED);
        $accounts = new InMemoryBankAccountRepository(BankAccountMother::create());
        $keystore = $this->createMock(Keystore::class);
        $keystore->method('lockState')->willReturn(KeyState::Live);
        $keystore->expects($this->once())->method('destroy')->willReturnCallback(function () use ($accounts): bool {
            $this->assertTrue($accounts->removeCalled, 'the key is destroyed after the account is removed');

            return true;
        });
        $audit = new RecordingAuditLogger();

        $result = $this->eraser($accounts, $keystore, $audit)->execute(BankAccountMother::DEFAULT_ID);

        $this->assertTrue($result->liveRecordErased);
        $this->assertTrue($result->keyDestroyed);
        $this->assertCount(1, $this->evidence->removed);
        $this->assertSame([], $this->evidence->links);
        $this->assertCount(1, $audit->records);
    }

    private function link(string $documentId): void
    {
        $this->evidence->add(BankAccountEvidence::attach(
            Uuid::generate(),
            BankAccountMother::DEFAULT_ID,
            $documentId,
            BankAccountEvidenceKind::OwnershipCertificate,
        ));
    }

    private function eraser(
        InMemoryBankAccountRepository $accounts,
        Keystore $keystore,
        RecordingAuditLogger $audit,
    ): EraseBankAccountSubject {
        return new EraseBankAccountSubject(
            $accounts,
            $keystore,
            $audit,
            new ImmediateTransactionManager(),
            new ReleaseBankAccountEvidence($this->evidence, $this->documents),
        );
    }
}
