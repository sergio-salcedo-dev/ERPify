<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Application\Command\AttachBankAccountEvidenceCommand;
use Erpify\Backoffice\BankAccount\Application\Evidence\AttachBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Application\Evidence\AttachedEvidence;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\EvidenceKind;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Exception\EvidenceAlreadyAttached;
use Erpify\Backoffice\BankAccount\Domain\Exception\EvidenceDocumentUnavailable;
use Erpify\Backoffice\BankAccount\Domain\Exception\EvidenceRetentionMismatch;
use Erpify\Documents\Document\Application\Evidence\DescribeEvidenceDocuments;
use Erpify\Documents\Document\Application\Evidence\EvidenceDocument;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\EvidenceDocumentsDouble;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\InMemoryBankAccountEvidenceRepository;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\InMemoryBankAccountRepository;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One test per refusal of the attach and per property of a successful one, against the real Documents seam.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per refusal and per property of the attach
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the use case, its four refusals and the seam it is tested against
 */
#[CoversClass(AttachBankAccountEvidence::class)]
#[CoversClass(AttachedEvidence::class)]
#[CoversClass(BankAccountEvidence::class)]
#[CoversClass(EvidenceKind::class)]
#[CoversClass(DescribeEvidenceDocuments::class)]
#[CoversClass(EvidenceDocument::class)]
#[CoversClass(EvidenceAlreadyAttached::class)]
#[CoversClass(EvidenceDocumentUnavailable::class)]
#[CoversClass(EvidenceRetentionMismatch::class)]
final class AttachBankAccountEvidenceTest extends TestCase
{
    private const string DOCUMENT = '0196f0a0-0000-7000-8000-00000000d001';

    private InMemoryBankAccountEvidenceRepository $evidence;

    private ImmediateTransactionManager $transactions;

    protected function setUp(): void
    {
        $this->evidence = new InMemoryBankAccountEvidenceRepository();
        $this->transactions = new ImmediateTransactionManager();
    }

    public function testAttachesAReadyDocumentFiledUnderItsKindsTerms(): void
    {
        $attached = $this->attach((new EvidenceDocumentsDouble())->withDocument(self::DOCUMENT));

        $this->assertCount(1, $this->evidence->links);
        $this->assertSame($attached->link, $this->evidence->links[0]);
        $this->assertSame(\strtolower(BankAccountMother::DEFAULT_ID), $attached->link->bankAccountId());
        $this->assertSame(self::DOCUMENT, $attached->link->documentId());
        $this->assertSame('ready', $attached->document?->state);
        $this->assertSame(1, $this->transactions->opened);
    }

    public function testADocumentStillBeingScannedCanBeAttached(): void
    {
        $documents = (new EvidenceDocumentsDouble())->withDocument(self::DOCUMENT, DocumentState::Received);
        $attached = $this->attach($documents);

        $this->assertSame('received', $attached->document?->state);
    }

    public function testStoresTheDocumentIdInItsCanonicalSpelling(): void
    {
        $attached = $this->attach(
            (new EvidenceDocumentsDouble())->withDocument(self::DOCUMENT),
            \strtoupper(self::DOCUMENT),
        );

        $this->assertSame(self::DOCUMENT, $attached->link->documentId());
    }

    #[DataProvider('provideRefusesADocumentThatCannotServeAsEvidenceCases')]
    public function testRefusesADocumentThatCannotServeAsEvidence(EvidenceDocumentsDouble $documents): void
    {
        try {
            $this->attach($documents);
            $this->fail('Expected EvidenceDocumentUnavailable.');
        } catch (EvidenceDocumentUnavailable $evidenceDocumentUnavailable) {
            $this->assertSame('bank-account-evidence-unavailable', $evidenceDocumentUnavailable->type());
        }

        $this->assertSame([], $this->evidence->links);
    }

    /**
     * @return iterable<string, array{EvidenceDocumentsDouble}>
     */
    public static function provideRefusesADocumentThatCannotServeAsEvidenceCases(): iterable
    {
        yield 'unknown' => [new EvidenceDocumentsDouble()];
        yield 'erased' => [(new EvidenceDocumentsDouble())->withDocument(self::DOCUMENT, DocumentState::Erased)];
        yield 'quarantined' => [
            (new EvidenceDocumentsDouble())->withDocument(self::DOCUMENT, DocumentState::Quarantined),
        ];
    }

    #[DataProvider('provideRefusesADocumentFiledUnderOtherRetentionTermsCases')]
    public function testRefusesADocumentFiledUnderOtherRetentionTerms(
        ?string $reason,
        ?string $basis,
        ?DateTimeImmutable $reviewAt,
    ): void {
        try {
            $this->attach(
                (new EvidenceDocumentsDouble())
                    ->withDocument(self::DOCUMENT, DocumentState::Ready, $reason, $basis, $reviewAt),
            );
            $this->fail('Expected EvidenceRetentionMismatch.');
        } catch (EvidenceRetentionMismatch $evidenceRetentionMismatch) {
            $this->assertSame('bank-account-evidence-retention-mismatch', $evidenceRetentionMismatch->type());
            // The expected terms, never the ones the document holds.
            $this->assertSame(
                [
                    'kind' => 'ownership-certificate',
                    'retentionReason' => 'contract-execution',
                    'legalBasis' => 'legitimate-interests',
                ],
                $evidenceRetentionMismatch->context(),
            );
        }

        $this->assertSame([], $this->evidence->links);
    }

    /**
     * @return iterable<string, array{?string, ?string, ?DateTimeImmutable}>
     */
    public static function provideRefusesADocumentFiledUnderOtherRetentionTermsCases(): iterable
    {
        yield 'another reason' => ['tax-and-accounting', 'legitimate-interests', null];
        yield 'another basis' => ['contract-execution', 'contract', null];
        yield 'a review date' => [
            'contract-execution',
            'legitimate-interests',
            new DateTimeImmutable('2031-01-01T00:00:00+00:00'),
        ];
        yield 'no terms at all' => [null, null, null];
    }

    public function testRefusesASecondLinkOfTheSameDocument(): void
    {
        $documents = (new EvidenceDocumentsDouble())->withDocument(self::DOCUMENT);
        $this->attach($documents);

        $this->expectException(EvidenceAlreadyAttached::class);

        $this->attach($documents, \strtoupper(self::DOCUMENT));
    }

    public function testAnswersNotFoundForAnUnknownAccountBeforeLookingAtTheDocument(): void
    {
        $documents = (new EvidenceDocumentsDouble())->withDocument(self::DOCUMENT);

        $refused = false;

        try {
            $this->useCase(new InMemoryBankAccountRepository(), $documents)
                ->attach(BankAccountMother::DEFAULT_ID, $this->command(self::DOCUMENT))
            ;
        } catch (BankAccountNotFoundException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'An unknown account answers 404.');
        $this->assertSame(0, $documents->findManyCalls());
    }

    public function testAnswersGoneWhileTheErasureLedgerOverrideIsSet(): void
    {
        $this->expectException(DocumentGone::class);

        $this->attach((new EvidenceDocumentsDouble())->withDocument(self::DOCUMENT)->withOverrideActive());
    }

    public function testRefusesAMalformedDocumentIdBeforeOpeningATransaction(): void
    {
        $refused = false;

        try {
            $this->attach((new EvidenceDocumentsDouble())->withDocument(self::DOCUMENT), 'not-a-uuid');
        } catch (InvalidUuidException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'A malformed id answers 400.');

        $this->assertSame(0, $this->transactions->opened);
    }

    private function attach(EvidenceDocumentsDouble $documents, string $documentId = self::DOCUMENT): AttachedEvidence
    {
        return $this->useCase(new InMemoryBankAccountRepository(BankAccountMother::drained()), $documents)
            ->attach(BankAccountMother::DEFAULT_ID, $this->command($documentId))
        ;
    }

    private function command(string $documentId): AttachBankAccountEvidenceCommand
    {
        return new AttachBankAccountEvidenceCommand($documentId, EvidenceKind::OwnershipCertificate);
    }

    private function useCase(
        InMemoryBankAccountRepository $accounts,
        EvidenceDocumentsDouble $documents,
    ): AttachBankAccountEvidence {
        return new AttachBankAccountEvidence($accounts, $this->evidence, $documents->seam(), $this->transactions);
    }
}
