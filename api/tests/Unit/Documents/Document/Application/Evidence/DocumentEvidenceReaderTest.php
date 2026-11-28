<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Application\Evidence;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Erpify\Documents\Document\Application\Evidence\DocumentEvidenceReader;
use Erpify\Documents\Document\Application\Evidence\EvidenceFiling;
use Erpify\Documents\Document\Application\Listing\DocumentNameOpener;
use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Documents\Document\Domain\Projection\DocumentListRow;
use Erpify\Documents\Document\Domain\Repository\DocumentListingRepository;
use Erpify\Documents\Document\Domain\Upload\LegalBasis;
use Erpify\Documents\Document\Domain\Upload\RetentionReason;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the seam, its read side and the vocabulary it is held to
 */
#[CoversClass(DocumentEvidenceReader::class)]
#[CoversClass(EvidenceFiling::class)]
final class DocumentEvidenceReaderTest extends TestCase
{
    private const string READY = '0197f2b4-3333-7000-8000-000000000001';

    private const string ERASED = '0197f2b4-3333-7000-8000-000000000002';

    #[Test]
    public function aFilingCarriesTheTermsAndStateAndOpensNoName(): void
    {
        $encryptor = $this->createMock(EnvelopeEncryptor::class);
        $encryptor->expects($this->never())->method('decryptMany');
        $listing = $this->createStub(DocumentListingRepository::class);
        $listing->method('find')->willReturn($this->row(self::READY, DocumentState::Ready));

        $filing = $this->reader($listing, $encryptor)->filingOf(self::READY);

        $this->assertInstanceOf(EvidenceFiling::class, $filing);
        $this->assertSame('ready', $filing->state);
        $this->assertFalse($filing->isErased());
        $this->assertTrue($filing->isFiledUnder('contract-execution', 'legitimate-interests', null));
    }

    #[Test]
    public function anUnknownDocumentHasNoFiling(): void
    {
        $this->assertNull(
            $this->reader($this->createStub(DocumentListingRepository::class))->filingOf(self::READY),
        );
    }

    #[Test]
    public function theFilingsOfASetAreKeyedById(): void
    {
        $listing = $this->createStub(DocumentListingRepository::class);
        $listing->method('findMany')->willReturn([
            $this->row(self::READY, DocumentState::Ready),
            $this->row(self::ERASED, DocumentState::Erased),
        ]);

        $filings = $this->reader($listing)->filingsOf([self::READY, self::ERASED]);

        $this->assertSame([self::READY, self::ERASED], \array_keys($filings));
        $this->assertArrayHasKey(self::ERASED, $filings);
        $this->assertTrue($filings[self::ERASED]->isErased());
    }

    #[Test]
    public function aDescriptionOpensTheNamesOfTheSetInOneQueryAndNeverAnErasedOne(): void
    {
        $encryptor = $this->createMock(EnvelopeEncryptor::class);
        $encryptor->expects($this->once())
            ->method('decryptMany')
            ->willReturnCallback(static function (array $values): array {
                self::assertSame([self::READY], \array_keys($values), 'the erased row costs no lookup');

                return [self::READY => 'certificate.pdf'];
            })
        ;
        $listing = $this->createStub(DocumentListingRepository::class);
        $listing->method('findMany')->willReturn([
            $this->row(self::READY, DocumentState::Ready),
            $this->row(self::ERASED, DocumentState::Erased),
        ]);

        $described = $this->reader($listing, $encryptor)->describe([self::READY, self::ERASED]);

        $this->assertArrayHasKey(self::READY, $described);
        $this->assertArrayHasKey(self::ERASED, $described);
        $this->assertSame('certificate.pdf', $described[self::READY]->name);
        $this->assertSame('ready', $described[self::READY]->state);
        $this->assertSame(\hash('sha256', self::READY), $described[self::READY]->digest);
        $this->assertNull($described[self::ERASED]->name);
        $this->assertSame('erased', $described[self::ERASED]->state);
    }

    #[Test]
    public function theLedgerOverrideRefusesADescriptionBeforeAnyRowIsRead(): void
    {
        $listing = $this->createMock(DocumentListingRepository::class);
        $listing->expects($this->never())->method('findMany');
        $override = $this->createStub(ErasureLedgerOverride::class);
        $override->method('isActive')->willReturn(true);

        $this->expectException(DocumentGone::class);

        (new DocumentEvidenceReader(
            $listing,
            new DocumentNameOpener($this->createStub(EnvelopeEncryptor::class)),
            $override,
        ))->describe([self::READY]);
    }

    #[Test]
    public function aFilingMatchesOnlyTheExactTermsAReviewDateIncluded(): void
    {
        $review = new DateTimeImmutable('2031-01-01T00:00:00+00:00');
        $filed = new EvidenceFiling(self::READY, 'ready', 'contract-execution', 'legitimate-interests', $review);

        $this->assertFalse($filed->isFiledUnder('contract-execution', 'legitimate-interests', null));
        $this->assertTrue($filed->isFiledUnder(
            'contract-execution',
            'legitimate-interests',
            new DateTimeImmutable('2031-01-01T01:00:00+01:00'),
        ));
        $this->assertFalse($filed->isFiledUnder('tax-and-accounting', 'legitimate-interests', $review));
    }

    /**
     * The consumer spells the Documents vocabulary as strings because it may not import it; this is where the two are
     * held equal, so a renamed case reds here rather than refusing every attach.
     */
    #[Test]
    public function everyEvidenceKindNamesTermsTheDocumentsVocabularyHolds(): void
    {
        foreach (BankAccountEvidenceKind::cases() as $kind) {
            $this->assertInstanceOf(RetentionReason::class, RetentionReason::tryFrom($kind->retentionReason()));
            $this->assertInstanceOf(LegalBasis::class, LegalBasis::tryFrom($kind->legalBasis()));
        }
    }

    private function reader(
        DocumentListingRepository $listing,
        ?EnvelopeEncryptor $encryptor = null,
    ): DocumentEvidenceReader {
        $override = $this->createStub(ErasureLedgerOverride::class);
        $override->method('isActive')->willReturn(false);

        return new DocumentEvidenceReader(
            $listing,
            new DocumentNameOpener($encryptor ?? $this->createStub(EnvelopeEncryptor::class)),
            $override,
        );
    }

    private function row(string $id, DocumentState $state): DocumentListRow
    {
        return new DocumentListRow(
            $id,
            $id,
            null,
            $state,
            \hash('sha256', $id),
            10,
            '0197f2b4-4444-4000-8000-' . \substr($id, -12),
            'ciphertext',
            'contract-execution',
            'legitimate-interests',
            null,
            new DateTimeImmutable('2030-05-05T10:00:00+00:00'),
        );
    }
}
