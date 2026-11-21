<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Erasure\ApplyErasure;
use Erpify\Documents\Document\Application\Erasure\EraseDocument;
use Erpify\Documents\Document\Application\Erasure\ErasureLedger;
use Erpify\Documents\Document\Application\Erasure\SeriesErasure;
use Erpify\Documents\Document\Application\Listing\DocumentSeriesPage;
use Erpify\Documents\Document\Application\Listing\ReadDocumentSeries;
use Erpify\Documents\Document\Application\Scanning\RescanDocument;
use Erpify\Documents\Document\Application\Upload\ClaimedUpload;
use Erpify\Documents\Document\Application\Upload\Command\DeclareUploadCommand;
use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Documents\Document\Application\Upload\DeclareUpload;
use Erpify\Documents\Document\Application\Upload\DeclareUploadOutcome;
use Erpify\Documents\Document\Application\Upload\IdempotencyKeyHasher;
use Erpify\Documents\Document\Application\Versioning\TakeVersionSlot;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Exception\ErasureLedgerUnavailable;
use Erpify\Documents\Document\Domain\Exception\IdempotencyKeyConflict;
use Erpify\Documents\Document\Domain\Exception\PredecessorNotReady;
use Erpify\Documents\Document\Domain\Exception\SeriesClosed;
use Erpify\Documents\Document\Domain\Exception\VersionSlotTaken;
use Erpify\Documents\Document\Domain\OriginalFilename;
use Erpify\Documents\Document\Domain\Projection\DocumentVersionRow;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Repository\DocumentSeriesRepository;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\RescanReason;
use Erpify\Documents\Document\Domain\Upload\LegalBasis;
use Erpify\Documents\Document\Domain\Upload\RetentionReason;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Documents\Document\Domain\Versioning\DocumentVersionSlots;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentSeriesRepository;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentVersionSlots;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Erasure\LedgerFailingFromTheNthWrite;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * The version slot as the specification states it, against real Postgres: a predecessor's slot is held while a live
 * version — `Received`, `Scanning`, `Ready` — exists anywhere below it, and the takes (a new version, a reopen)
 * decide that under the series lock (ADR `documents-custody-log.md` D12). Freeing transitions run through the real
 * erasure and the real scan compare-and-set, which take no series lock, so every "slot free" here is derived and never
 * written.
 *
 * The interleaved pairs live in {@see VersionSlotPairsFunctionalTest}.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one case per property of the slot
 * @SuppressWarnings("PHPMD.TooManyMethods") the same, with the helpers that keep each case to its claim
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") the same
 */
#[CoversClass(CommitUpload::class)]
#[CoversClass(RescanDocument::class)]
#[CoversClass(TakeVersionSlot::class)]
#[CoversClass(DeclareUpload::class)]
#[CoversClass(EraseDocument::class)]
#[CoversClass(ReadDocumentSeries::class)]
#[CoversClass(DbalDocumentVersionSlots::class)]
#[CoversClass(DbalDocumentSeriesRepository::class)]
final class DocumentVersioningFunctionalTest extends KernelTestCase
{
    use PlantsVersionSeries;
    use ResolvesContainerServices;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->emptyDocumentTables();
    }

    protected function tearDown(): void
    {
        $this->emptyDocumentTables();

        parent::tearDown();
    }

    #[Test]
    public function aNewVersionOfAReadyDocumentJoinsItsSeriesAndLinksToIt(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $second = $this->plantVersion(DocumentState::Ready, $first);

        $third = $this->commitVersion($this->streamingVersionOf($second));

        $this->assertSame($first, $third->seriesId(), 'the series is copied from the locked predecessor');
        $this->assertSame($second, $third->predecessorId());
        $this->assertSame('received', $this->stateOfDocument($third->documentId()));
        $this->assertSame($first, $this->seriesOf($third->documentId()));
    }

    #[Test]
    #[DataProvider('provideAPredecessorThatIsNotReadyIsRefusedLikeAnAbsentOneCases')]
    public function aPredecessorThatIsNotReadyIsRefusedLikeAnAbsentOne(?DocumentState $state): void
    {
        $predecessor = $state instanceof DocumentState ? $this->plantVersion($state) : Uuid::generate();
        $claimed = $this->streamingVersionOf($predecessor);

        $this->assertThrows(PredecessorNotReady::class, fn (): Document => $this->commitVersion($claimed));
        $this->assertSame(
            $state instanceof DocumentState ? 1 : 0,
            $this->rowsIn('document'),
            'the refused commit filed nothing',
        );
        $this->assertSame('streaming', $this->intentState($claimed->intentId), "left to the writer's abort");
    }

    /**
     * @return iterable<string, array{?DocumentState}>
     */
    public static function provideAPredecessorThatIsNotReadyIsRefusedLikeAnAbsentOneCases(): iterable
    {
        yield 'absent' => [null];

        foreach (DocumentState::cases() as $state) {
            if (DocumentState::Ready !== $state) {
                yield $state->value => [$state];
            }
        }
    }

    #[Test]
    public function aSecondLiveSuccessorIsRefused(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $this->plantVersion(DocumentState::Received, $first);

        $this->assertCommitRefused(VersionSlotTaken::class, $first);
    }

    #[Test]
    public function erasingAMiddleNodeKeepsTheSlotAboveItHeldByTheLiveVersionBelowIt(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $middle = $this->plantVersion(DocumentState::Ready, $first);
        $this->plantVersion(DocumentState::Ready, $middle);

        $this->erase($middle);

        $this->assertCommitRefused(VersionSlotTaken::class, $first);
        $this->assertCommitRefused(PredecessorNotReady::class, $middle);
    }

    #[Test]
    public function erasingTheOnlyLiveDescendantOfARunOfErasedNodesFreesTheSlotOfTheFirst(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $second = $this->plantVersion(DocumentState::Ready, $first);
        $third = $this->plantVersion(DocumentState::Ready, $second);
        $tip = $this->plantVersion(DocumentState::Ready, $third);
        $this->erase($second);
        $this->erase($third);

        $this->assertCommitRefused(VersionSlotTaken::class, $first);

        $this->erase($tip);

        $sibling = $this->commitVersion($this->streamingVersionOf($first));
        $this->assertSame($first, $sibling->predecessorId(), 'filed as a sibling of the first erased node');
    }

    #[Test]
    public function erasingTheLastDescendantFreesTheSlot(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $only = $this->plantVersion(DocumentState::Ready, $first);

        $this->erase($only);

        $this->assertSame($first, $this->commitVersion($this->streamingVersionOf($first))->predecessorId());
    }

    #[Test]
    public function quarantiningTheOnlyChildOfAnErasedNodeFreesTheSlot(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $erased = $this->plantVersion(DocumentState::Ready, $first);
        $child = $this->plantVersion(DocumentState::Scanning, $erased);
        $this->connection()->executeStatement(
            'UPDATE document SET lease_epoch = 1, claimed_at = NOW() WHERE id = CAST(:id AS UUID)',
            ['id' => $child],
        );
        $this->erase($erased);

        $this->assertCommitRefused(VersionSlotTaken::class, $first);

        $this->assertTrue($this->service(DocumentScanLeases::class)->settle($child, 1, DocumentState::Quarantined));

        $this->assertSame($first, $this->commitVersion($this->streamingVersionOf($first))->predecessorId());
    }

    #[Test]
    public function aReopenWhosePredecessorWasErasedIsRefusedAndChangesNothing(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $failed = $this->plantVersion(DocumentState::ScanFailed, $first);
        $this->erase($first);

        $this->assertThrows(PredecessorNotReady::class, fn () => $this->reopen($failed));
        $this->assertSame('scan_failed', $this->stateOfDocument($failed));
    }

    #[Test]
    public function aReopenWhoseSlotASiblingHoldsIsRefusedAndChangesNothing(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $failed = $this->plantVersion(DocumentState::ScanFailed, $first);
        $this->plantVersion(DocumentState::Ready, $first);

        $this->assertThrows(VersionSlotTaken::class, fn () => $this->reopen($failed));
        $this->assertSame('scan_failed', $this->stateOfDocument($failed));
    }

    #[Test]
    public function aReopenWhoseSlotIsFreeTakesItBack(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $failed = $this->plantVersion(DocumentState::ScanFailed, $first);
        $this->plantVersion(DocumentState::Quarantined, $first);

        $this->reopen($failed);

        $this->assertSame('received', $this->stateOfDocument($failed));
        $this->assertCommitRefused(VersionSlotTaken::class, $first);
    }

    #[Test]
    public function theDeclareRefusesAnObviousConflictBeforeAByteIsSentAndRecordsThePredecessorOtherwise(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $received = $this->plantVersion(DocumentState::Received);
        $actor = Uuid::generate();

        $this->assertDeclareRefused(PredecessorNotReady::class, $actor, Uuid::generate());
        $this->assertDeclareRefused(PredecessorNotReady::class, $actor, $received);

        $outcome = $this->declareVersion($actor, $first, 'key-1');
        $this->assertTrue($outcome->created);
        $intentId = $outcome->intentId();
        $this->assertNotNull($intentId);
        $this->forgetAfterTest($intentId);
        $this->assertSame($first, $this->connection()->fetchOne(
            'SELECT predecessor_id FROM upload_intent WHERE id = CAST(:id AS UUID)',
            ['id' => $intentId],
        ));

        $this->assertFalse($this->declareVersion($actor, $first, 'key-1')->created, 'an identical replay');
        $other = $this->plantVersion(DocumentState::Ready);
        $this->assertDeclareRefused(IdempotencyKeyConflict::class, $actor, $other, 'key-1');

        $this->plantVersion(DocumentState::Scanning, $first);
        $this->assertDeclareRefused(VersionSlotTaken::class, $actor, $first);
    }

    #[Test]
    public function theSeriesReadsInCreationOrderAndATombstoneNamesNoUploader(): void
    {
        $uploader = Uuid::generate();
        $first = $this->plantVersion(DocumentState::Ready, uploaderId: $uploader);
        $second = $this->plantVersion(DocumentState::Ready, $first, $uploader);
        $third = $this->plantVersion(DocumentState::Received, $second, ErasedPersonMarker::UUID);
        $this->connection()->executeStatement(
            "UPDATE document SET retention_reason = 'technical-file', legal_basis = 'contract', "
            . "review_at = '2031-03-04 05:06:07+00' WHERE id = CAST(:id AS UUID)",
            ['id' => $second],
        );
        $this->erase($second);
        $this->plantVersion(DocumentState::Ready);

        $reader = $this->service(ReadDocumentSeries::class);
        $page = $reader->read($first, null, 2);

        $this->assertSame([$first, $second], $this->idsOf($page));
        $this->assertSame($uploader, $this->versionAt($page, 0)->uploaderId);
        $tombstone = $this->versionAt($page, 1);
        $this->assertNull($tombstone->uploaderId, 'an erased version names nobody');
        $this->assertSame(DocumentState::Erased, $tombstone->state);
        $this->assertSame(\hash('sha256', $second), $tombstone->digest, 'the tombstone keeps its digest');
        $this->assertSame($first, $tombstone->predecessorId);
        $this->assertSame('technical-file', $tombstone->retentionReason, 'the tombstone keeps its retention terms');
        $this->assertSame('contract', $tombstone->legalBasis);
        $this->assertSame('2031-03-04T05:06:07+00:00', $tombstone->reviewAt?->format(DATE_ATOM));
        $this->assertSame($second, $page->nextAfterId);

        $next = $reader->read($first, $page->nextAfterId, 2);
        $this->assertSame([$third], $this->idsOf($next));
        $this->assertSame(ErasedPersonMarker::UUID, $this->versionAt($next, 0)->uploaderId, 'a marker, never a user');
        $this->assertNull($next->nextAfterId);

        $this->assertThrows(DocumentNotFound::class, static fn () => $reader->read($second, null, 10));
    }

    #[Test]
    public function theSeriesIsOrderedByCreationThenByIdNeverByIdAlone(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $later = $this->plantVersion(DocumentState::Erased, $first);
        $tieLow = $this->plantVersion(DocumentState::Erased, $first);
        $tieHigh = $this->plantVersion(DocumentState::Ready, $first);
        $this->connection()->executeStatement(
            "UPDATE document SET created_at = created_at + INTERVAL '1 hour' WHERE id = CAST(:id AS UUID)",
            ['id' => $later],
        );
        $this->connection()->executeStatement(
            'UPDATE document SET created_at = (SELECT created_at FROM document WHERE id = CAST(:low AS UUID)) '
            . 'WHERE id = CAST(:high AS UUID)',
            ['low' => $tieLow, 'high' => $tieHigh],
        );

        $page = $this->service(ReadDocumentSeries::class)->read($first, null, 10);

        $this->assertSame([$first, $tieLow, $tieHigh, $later], $this->idsOf($page));
    }

    #[Test]
    public function aSeriesErasureErasesNewestFirstEachInItsOwnTransactionAndReportsWhatRemains(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $failed = $this->plantVersion(DocumentState::Quarantined, $first);
        $second = $this->plantVersion(DocumentState::Ready, $first);
        $alreadyErased = $this->plantVersion(DocumentState::Erased, $second);
        $unrelated = $this->plantVersion(DocumentState::Ready);

        $erasure = $this->service(EraseDocument::class)
            ->eraseSeries($first, ErasureReason::RetentionExpired, Uuid::generate())
        ;

        $this->assertSame([$second, $failed, $first], $erasure->erased);
        $this->assertSame([], $erasure->remaining);
        $this->assertSame($first, $erasure->seriesId);

        foreach ([$first, $failed, $second, $alreadyErased] as $version) {
            $this->assertSame('erased', $this->stateOfDocument($version));
        }

        $this->assertSame('ready', $this->stateOfDocument($unrelated));
        $this->assertSame(1, $this->intOf($this->connection()->fetchOne(
            "SELECT COUNT(*) FROM document_custody WHERE document_id = CAST(:id AS UUID) AND event = 'erased'",
            ['id' => $second],
        )));

        $repeat = $this->service(EraseDocument::class)
            ->eraseSeries($first, ErasureReason::RetentionExpired, Uuid::generate())
        ;
        $this->assertSame([], $repeat->erased);
        $this->assertSame([], $repeat->remaining);
        $this->assertNotFalse($this->connection()->fetchOne(
            'SELECT closed_at FROM document_series WHERE id = CAST(:id AS UUID) AND closed_at IS NOT NULL',
            ['id' => $first],
        ), 'the series erasure closed the series');

        $this->assertThrows(
            DocumentNotFound::class,
            fn () => $this->service(EraseDocument::class)->eraseSeries(
                $second,
                ErasureReason::RetentionExpired,
                Uuid::generate(),
            ),
        );
    }

    #[Test]
    public function aSeriesClosedByAnErasureAdmitsNoVersionAgainWhileItsVersionsAreStillLive(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $failed = $this->plantVersion(DocumentState::ScanFailed, $first);
        $claimed = $this->streamingVersionOf($first);
        $actor = Uuid::generate();

        $this->closeSeries($first);

        $this->assertThrows(SeriesClosed::class, fn (): Document => $this->commitVersion($claimed));
        $this->assertThrows(SeriesClosed::class, fn () => $this->reopen($failed));
        $this->assertDeclareRefused(SeriesClosed::class, $actor, $first);
        $this->assertSame('ready', $this->stateOfDocument($first), 'closing erases nothing by itself');
        $this->assertSame('scan_failed', $this->stateOfDocument($failed));
        $this->assertSame(0, $this->liveChildrenCount($first));
    }

    #[Test]
    public function aFailedFirstVersionOfAClosedSeriesIsNotReopened(): void
    {
        $first = $this->plantVersion(DocumentState::ScanFailed);

        $this->closeSeries($first);

        $this->assertThrows(SeriesClosed::class, fn () => $this->reopen($first));
        $this->assertSame('scan_failed', $this->stateOfDocument($first));
    }

    #[Test]
    public function aKeyAFiledVersionHoldsIsReplayedAsThatVersionAndConflictsWithAnyOtherPredecessor(): void
    {
        $actor = Uuid::generate();
        $first = $this->plantVersion(DocumentState::Ready);
        $other = $this->plantVersion(DocumentState::Ready);
        $version = $this->plantVersion(DocumentState::Ready, $first);
        $this->connection()->executeStatement(
            'UPDATE document SET idempotency_key_hash = :key, digest = :digest, retention_reason = :reason, '
            . 'legal_basis = :basis WHERE id = CAST(:id AS UUID)',
            [
                'key' => $this->service(IdempotencyKeyHasher::class)->hash($actor, 'filed-key'),
                'digest' => \hash('sha256', 'v'),
                'reason' => RetentionReason::TechnicalFile->value,
                'basis' => LegalBasis::Contract->value,
                'id' => $version,
            ],
        );

        $replay = $this->declareVersion($actor, $first, 'filed-key');
        $this->assertFalse($replay->created, 'the filed version answers its own replay, not the slot it holds');

        $this->assertDeclareRefused(IdempotencyKeyConflict::class, $actor, $other, 'filed-key');
        $this->assertThrows(
            IdempotencyKeyConflict::class,
            fn (): DeclareUploadOutcome => $this->service(DeclareUpload::class)->declare(
                $this->versionCommand('filed-key'),
                $actor,
            ),
        );
    }

    #[Test]
    public function aSeriesErasureStopsAtTheFirstLedgerFailureKeepsWhatItErasedAndARepeatFinishesIt(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $second = $this->plantVersion(DocumentState::Ready, $first);
        $third = $this->plantVersion(DocumentState::Received, $second);
        $ledger = $this->ledgerFailingFrom(2);

        $this->assertThrows(
            ErasureLedgerUnavailable::class,
            fn (): SeriesErasure => $this->eraseSeriesWith($ledger, $first),
        );

        $this->assertSame('erased', $this->stateOfDocument($third), 'the newest version stays erased');
        $this->assertSame('ready', $this->stateOfDocument($second), 'the failed version is untouched');
        $this->assertSame('ready', $this->stateOfDocument($first));
        $this->assertSame([$this->scopeOf($third), $this->scopeOf($second)], $ledger->attempts());
        $this->assertNotFalse($this->connection()->fetchOne(
            'SELECT 1 FROM document_series WHERE id = CAST(:id AS UUID) AND closed_at IS NOT NULL',
            ['id' => $first],
        ), 'the series stays closed');

        $repeat = $this->eraseSeriesWith($this->ledgerFailingFrom(PHP_INT_MAX), $first);

        $this->assertSame([$second, $first], $repeat->erased);
        $this->assertSame([], $repeat->remaining);
    }

    #[Test]
    public function aFirstVersionFiledThroughTheRepositoryOpensItsSeriesRow(): void
    {
        $first = $this->commitFirstVersion();

        $this->assertSame($first, $this->connection()->fetchOne(
            'SELECT id FROM document_series WHERE id = CAST(:id AS UUID)',
            ['id' => $first],
        ));
        $this->assertSame(1, $this->rowsIn('document_series'));
    }

    private function commitFirstVersion(): string
    {
        $intent = $this->plantIntent(Uuid::generate(), UploadIntentState::Streaming);
        $this->mintKey($intent->scopeId);

        return $this->commitVersion(new ClaimedUpload(
            $intent->id,
            $intent->scopeId,
            10,
            null,
            OriginalFilename::fromString('first.pdf'),
        ))->documentId();
    }

    /**
     * @return list<string>
     */
    private function idsOf(DocumentSeriesPage $page): array
    {
        return \array_map(static fn (DocumentVersionRow $row): string => $row->id, $page->versions);
    }

    /**
     * @param class-string<Throwable> $expected
     */
    private function assertCommitRefused(string $expected, string $predecessorId): void
    {
        $claimed = $this->streamingVersionOf($predecessorId);

        $this->assertThrows($expected, fn (): Document => $this->commitVersion($claimed));
    }

    /**
     * @param class-string<Throwable> $expected
     */
    private function assertDeclareRefused(
        string $expected,
        string $actor,
        string $predecessorId,
        ?string $key = null,
    ): void {
        $this->assertThrows(
            $expected,
            fn (): DeclareUploadOutcome => $this->declareVersion($actor, $predecessorId, $key),
        );
    }

    private function versionAt(DocumentSeriesPage $page, int $index): DocumentVersionRow
    {
        $row = $page->versions[$index] ?? null;
        $this->assertInstanceOf(DocumentVersionRow::class, $row);

        return $row;
    }

    private function erase(string $documentId): void
    {
        $this->assertTrue(
            $this->service(ApplyErasure::class)->apply(
                $documentId,
                Uuid::generate(),
                ErasureReason::UploadedInError->value,
            ),
        );
    }

    private function reopen(string $documentId): void
    {
        $this->service(RescanDocument::class)->rescan($documentId, RescanReason::FailureInvestigated, Uuid::generate());
    }

    private function declareVersion(
        string $actor,
        string $predecessorId,
        ?string $key = null,
    ): DeclareUploadOutcome {
        $outcome = $this->service(DeclareUpload::class)->declareVersion(
            $this->versionCommand($key),
            $actor,
            $predecessorId,
        );
        $intentId = $outcome->intentId();

        if (null !== $intentId) {
            $this->forgetAfterTest($intentId);
        }

        return $outcome;
    }

    private function liveChildrenCount(string $predecessorId): int
    {
        return $this->intOf($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM document WHERE predecessor_id = CAST(:id AS UUID) '
            . "AND state IN ('received', 'scanning', 'ready')",
            ['id' => $predecessorId],
        ));
    }

    private function versionCommand(?string $key): DeclareUploadCommand
    {
        return new DeclareUploadCommand(
            size: 10,
            sha256: \hash('sha256', 'v'),
            idempotencyKey: $key,
            retentionReason: RetentionReason::TechnicalFile,
            legalBasis: LegalBasis::Contract,
        );
    }

    private function closeSeries(string $seriesId): void
    {
        $this->service(TransactionManager::class)->transactional(function () use ($seriesId): void {
            $this->service(DocumentVersionSlots::class)->close($seriesId);
        });
    }

    private function eraseSeriesWith(ErasureLedger $ledger, string $seriesId): SeriesErasure
    {
        $erase = new EraseDocument(
            $this->service(DocumentRepository::class),
            $ledger,
            $this->service(ApplyErasure::class),
            new NullLogger(),
            $this->service(DocumentSeriesRepository::class),
            $this->service(DocumentVersionSlots::class),
            $this->service(TransactionManager::class),
        );

        return $erase->eraseSeries($seriesId, ErasureReason::RetentionExpired, Uuid::generate());
    }

    private function ledgerFailingFrom(int $failing): LedgerFailingFromTheNthWrite
    {
        return new LedgerFailingFromTheNthWrite($failing);
    }

    private function scopeOf(string $documentId): string
    {
        return $this->stringOf($this->connection()->fetchOne(
            'SELECT scope_id FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $documentId],
        ));
    }

    private function intentState(string $intentId): string
    {
        return $this->stringOf($this->connection()->fetchOne(
            'SELECT state FROM upload_intent WHERE id = CAST(:id AS UUID)',
            ['id' => $intentId],
        ));
    }

    /**
     * @param class-string<Throwable> $expected
     */
    private function assertThrows(string $expected, callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            $this->assertInstanceOf($expected, $throwable, $throwable->getMessage());

            return;
        }

        $this->fail(\sprintf('Expected %s.', $expected));
    }
}
