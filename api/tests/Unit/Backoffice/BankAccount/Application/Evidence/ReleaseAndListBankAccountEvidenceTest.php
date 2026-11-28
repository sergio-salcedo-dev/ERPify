<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Application\BankAccountFinder;
use Erpify\Backoffice\BankAccount\Application\Evidence\DescribeEvidence;
use Erpify\Backoffice\BankAccount\Application\Evidence\ListBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Application\Evidence\ReleaseBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountHasLiveEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\InMemoryBankAccountRepository;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") two use cases and the doubles they share
 */
#[CoversClass(ReleaseBankAccountEvidence::class)]
#[CoversClass(ListBankAccountEvidence::class)]
#[CoversClass(DescribeEvidence::class)]
#[CoversClass(BankAccountHasLiveEvidence::class)]
final class ReleaseAndListBankAccountEvidenceTest extends TestCase
{
    private const string ERASED = '0197f2b4-2222-7000-8000-000000000001';

    private const string LIVE = '0197f2b4-2222-7000-8000-000000000002';

    private const string OTHER_ACCOUNT = '33333333-3333-7000-8000-000000000099';

    private InMemoryBankAccountEvidenceRepository $evidence;

    private FakeEvidenceDocuments $documents;

    protected function setUp(): void
    {
        $this->evidence = new InMemoryBankAccountEvidenceRepository();
        $this->documents = new FakeEvidenceDocuments();
    }

    #[Test]
    public function anAccountWithNoEvidenceIsReleasedWithoutAskingDocuments(): void
    {
        $this->release()->releaseAll(BankAccountMother::DEFAULT_ID);

        $this->assertSame([], $this->evidence->removed);
    }

    #[Test]
    public function linksWhoseDocumentsAreAllErasedAreRemovedAndOtherAccountsKeepTheirs(): void
    {
        $this->documents->file(self::ERASED, 'erased');
        $this->link(BankAccountMother::DEFAULT_ID, self::ERASED);
        $kept = $this->link(self::OTHER_ACCOUNT, self::ERASED);

        $this->release()->releaseAll(BankAccountMother::DEFAULT_ID);

        $this->assertCount(1, $this->evidence->removed);
        $this->assertSame([$kept], $this->evidence->links);
    }

    #[Test]
    public function oneLinkedDocumentThatIsNotErasedRefusesAndRemovesNothing(): void
    {
        $this->documents->file(self::ERASED, 'erased');
        $this->documents->file(self::LIVE, 'quarantined');
        $this->link(BankAccountMother::DEFAULT_ID, self::ERASED);
        $this->link(BankAccountMother::DEFAULT_ID, self::LIVE);

        try {
            $this->release()->releaseAll(BankAccountMother::DEFAULT_ID);
            $this->fail('Expected the release to refuse.');
        } catch (BankAccountHasLiveEvidence $refusal) {
            $this->assertSame('bank-account-has-live-evidence', $refusal->type());
            $this->assertSame(['bankAccountId' => BankAccountMother::DEFAULT_ID], $refusal->context());
        }

        $this->assertSame([], $this->evidence->removed);
        $this->assertCount(2, $this->evidence->links);
    }

    #[Test]
    public function theListingComposesEachLinkWithItsDocumentInOneCall(): void
    {
        $this->documents->file(self::ERASED, 'erased');
        $this->documents->file(self::LIVE, 'ready');
        $this->link(BankAccountMother::DEFAULT_ID, self::ERASED);
        $this->link(BankAccountMother::DEFAULT_ID, self::LIVE);

        $listed = $this->listing()->list(BankAccountMother::DEFAULT_ID);

        $this->assertCount(2, $listed);
        $this->assertSame([self::ERASED, self::LIVE], \array_column($listed, 'documentId'));
        $this->assertNull($listed[0]->name, 'an erased document lists as its tombstone');
        $this->assertSame('erased', $listed[0]->state);
        $this->assertSame(self::LIVE . '.pdf', $listed[1]->name);
        $this->assertSame(1, $this->documents->describeCalls);
    }

    #[Test]
    public function theListingOfAnUnknownAccountIsNotFound(): void
    {
        $this->expectException(BankAccountNotFoundException::class);

        (new ListBankAccountEvidence(
            new BankAccountFinder(new InMemoryBankAccountRepository()),
            $this->evidence,
            new DescribeEvidence($this->documents),
        ))->list(BankAccountMother::DEFAULT_ID);
    }

    #[Test]
    public function anEmptyListingAsksDocumentsNothing(): void
    {
        $this->assertSame([], $this->listing()->list(BankAccountMother::DEFAULT_ID));
        $this->assertSame(0, $this->documents->describeCalls);
    }

    private function link(string $accountId, string $documentId): BankAccountEvidence
    {
        $link = BankAccountEvidence::attach(
            Uuid::generate(),
            $accountId,
            $documentId,
            BankAccountEvidenceKind::OwnershipCertificate,
        );
        $this->evidence->add($link);

        return $link;
    }

    private function release(): ReleaseBankAccountEvidence
    {
        return new ReleaseBankAccountEvidence($this->evidence, $this->documents);
    }

    private function listing(): ListBankAccountEvidence
    {
        return new ListBankAccountEvidence(
            new BankAccountFinder(new InMemoryBankAccountRepository(BankAccountMother::create())),
            $this->evidence,
            new DescribeEvidence($this->documents),
        );
    }
}
