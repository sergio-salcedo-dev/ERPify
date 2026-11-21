<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Erasure\ApplyErasure;
use Erpify\Documents\Document\Application\Erasure\EraseDocument;
use Erpify\Documents\Document\Application\Erasure\SeriesErasure;
use Erpify\Documents\Document\Application\Scanning\RescanDocument;
use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Documents\Document\Application\Versioning\TakeVersionSlot;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Documents\Document\Domain\Exception\PredecessorNotReady;
use Erpify\Documents\Document\Domain\Exception\VersionSlotTaken;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\RescanReason;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentVersionSlots;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;
use Throwable;

/**
 * The pairs of ADR `documents-custody-log.md` D12, interleaved for real: a second connection holds what one side
 * holds mid-transaction, and the other side either WAITS on it and, once the holder commits, takes the one outcome
 * left to it, or completes without waiting at all.
 *
 * What is shown, pair by pair: two new versions of one predecessor serialise on the series row and one wins; a new
 * version and the predecessor's erasure meet on the predecessor row only; a new version and a reopen, and two
 * reopens, serialise on the series row and one wins; the series erasure's closing waits for a take in flight and then
 * lists what it filed; and the freeing transitions — the erasure and the scan's compare-and-set — never wait on the
 * series row, which is why they need no change.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per pair and direction
 */
#[CoversClass(CommitUpload::class)]
#[CoversClass(EraseDocument::class)]
#[CoversClass(RescanDocument::class)]
#[CoversClass(TakeVersionSlot::class)]
#[CoversClass(DbalDocumentVersionSlots::class)]
final class VersionSlotPairsFunctionalTest extends KernelTestCase
{
    use InterleavesWithASecondConnection;
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
        $this->releaseTheHolder();
        $this->emptyDocumentTables();

        parent::tearDown();
    }

    #[Test]
    public function twoNewVersionsOfOnePredecessorSerialiseOnTheSeriesAndOneWins(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $claimed = $this->streamingVersionOf($first);

        $this->holdTheSlotFor($first);
        $this->holder()->executeStatement(...$this->successorInsert($first, DocumentState::Received));

        $this->assertTrue(
            $this->waits(fn (): Document => $this->commitVersion($claimed)),
            'the second waits on the series',
        );

        $this->holder()->commit();

        $this->assertRefusedWith(VersionSlotTaken::class, fn (): Document => $this->commitVersion($claimed));
        $this->assertSame(1, $this->liveChildrenOf($first), 'one successor, never two');
    }

    #[Test]
    public function aNewVersionMeetingThePredecessorsErasureWaitsAndThenFindsItErased(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $claimed = $this->streamingVersionOf($first);

        $holder = $this->holder();
        $holder->beginTransaction();
        $holder->executeStatement(
            "UPDATE document SET state = 'erased' WHERE id = CAST(:id AS UUID)",
            ['id' => $first],
        );

        $this->assertTrue(
            $this->waits(fn (): Document => $this->commitVersion($claimed)),
            'it waits on the predecessor row',
        );

        $holder->commit();

        $this->assertRefusedWith(PredecessorNotReady::class, fn (): Document => $this->commitVersion($claimed));
    }

    #[Test]
    public function anErasureMeetingANewVersionInFlightWaitsOnThePredecessorAndLeavesTheNewVersionLive(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);

        $this->holdTheSlotFor($first);
        $this->holder()->executeStatement(...$this->successorInsert($first, DocumentState::Received));

        $this->assertTrue($this->waits(fn () => $this->erase($first)), 'it waits on the predecessor row');

        $this->holder()->commit();

        $this->erase($first);
        $this->assertSame('erased', $this->stateOfDocument($first));
        $this->assertSame(1, $this->liveChildrenOf($first), 'the new version stays live under its erased predecessor');
    }

    #[Test]
    public function theFreeingTransitionsNeverWaitOnTheSeriesRow(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $scanning = $this->plantVersion(DocumentState::Scanning, $first);
        $this->connection()->executeStatement(
            'UPDATE document SET lease_epoch = 1, claimed_at = NOW() WHERE id = CAST(:id AS UUID)',
            ['id' => $scanning],
        );
        $sibling = $this->plantVersion(DocumentState::Ready);

        $holder = $this->holder();
        $holder->beginTransaction();
        $holder->fetchOne('SELECT id FROM document_series WHERE id = CAST(:id AS UUID) FOR UPDATE', ['id' => $first]);

        $this->assertFalse($this->waits(
            fn () => $this->service(DocumentScanLeases::class)->settle($scanning, 1, DocumentState::Quarantined),
        ));
        $this->assertFalse($this->waits(fn () => $this->erase($first)));
        $this->assertFalse($this->waits(fn () => $this->erase($sibling)));
        $this->assertSame('quarantined', $this->stateOfDocument($scanning));
    }

    #[Test]
    public function aNewVersionMeetingAReopenInFlightWaitsAndThenFindsTheSlotTaken(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $failed = $this->plantVersion(DocumentState::ScanFailed, $first);
        $claimed = $this->streamingVersionOf($first);

        $this->holdTheSlotFor($first);
        $this->holder()->executeStatement(
            "UPDATE document SET state = 'received' WHERE id = CAST(:id AS UUID)",
            ['id' => $failed],
        );

        $this->assertTrue($this->waits(fn (): Document => $this->commitVersion($claimed)));

        $this->holder()->commit();

        $this->assertRefusedWith(VersionSlotTaken::class, fn (): Document => $this->commitVersion($claimed));
    }

    #[Test]
    public function aReopenMeetingANewVersionInFlightWaitsAndThenFindsTheSlotTaken(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $failed = $this->plantVersion(DocumentState::ScanFailed, $first);

        $this->holdTheSlotFor($first);
        $this->holder()->executeStatement(...$this->successorInsert($first, DocumentState::Received));

        $this->assertTrue($this->waits(fn () => $this->reopen($failed)));

        $this->holder()->commit();

        $this->assertRefusedWith(VersionSlotTaken::class, fn () => $this->reopen($failed));
        $this->assertSame('scan_failed', $this->stateOfDocument($failed));
    }

    #[Test]
    public function ofTwoConcurrentReopensOfFailedSiblingsOneWins(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $winner = $this->plantVersion(DocumentState::ScanFailed, $first);
        $loser = $this->plantVersion(DocumentState::ScanFailed, $first);

        $this->holdTheSlotFor($first);
        $this->holder()->executeStatement(
            "UPDATE document SET state = 'received' WHERE id = CAST(:id AS UUID)",
            ['id' => $winner],
        );

        $this->assertTrue($this->waits(fn () => $this->reopen($loser)));

        $this->holder()->commit();

        $this->assertRefusedWith(VersionSlotTaken::class, fn () => $this->reopen($loser));
        $this->assertSame('scan_failed', $this->stateOfDocument($loser));
        $this->assertSame(1, $this->liveChildrenOf($first));
    }

    #[Test]
    public function aSeriesErasureMeetingATakeInFlightWaitsAndThenListsTheVersionTheTakeFiled(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);

        $this->holdTheSlotFor($first);
        [$insert, $parameters] = $this->successorInsert($first, DocumentState::Received);
        $filed = $parameters['id'] ?? $this->fail('The successor insert names its id.');
        $this->holder()->executeStatement($insert, $parameters);

        $this->assertTrue(
            $this->waits(fn (): SeriesErasure => $this->eraseSeries($first)),
            'closing the series waits for the take that holds it',
        );

        $this->holder()->commit();

        $erasure = $this->eraseSeries($first);

        $this->assertSame([$filed, $first], $erasure->erased, 'the filed version is erased with the rest');
        $this->assertSame([], $erasure->remaining);
    }

    /**
     * What a take holds once it has decided: the series row, then the predecessor row, in that order.
     */
    private function holdTheSlotFor(string $predecessorId): void
    {
        $holder = $this->holder();
        $holder->beginTransaction();
        $holder->fetchOne(
            'SELECT id FROM document_series WHERE id = CAST(:id AS UUID) FOR UPDATE',
            ['id' => $this->seriesOf($predecessorId)],
        );
        $holder->fetchOne('SELECT id FROM document WHERE id = CAST(:id AS UUID) FOR UPDATE', ['id' => $predecessorId]);
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function successorInsert(string $predecessorId, DocumentState $state): array
    {
        $id = Uuid::generate();

        return [
            'INSERT INTO document (id, scope_id, series_id, predecessor_id, uploader_id, state, digest, size_bytes, '
            . 'next_scan_at, created_at, updated_at) VALUES (:id, :scope, :series, :predecessor, :uploader, :state, '
            . ':digest, 10, NOW(), NOW(), NOW())',
            [
                'id' => $id,
                'scope' => RandomUuid::v4()->toRfc4122(),
                'series' => $this->seriesOf($predecessorId),
                'predecessor' => $predecessorId,
                'uploader' => Uuid::generate(),
                'state' => $state->value,
                'digest' => \hash('sha256', $id),
            ],
        ];
    }

    private function liveChildrenOf(string $predecessorId): int
    {
        return $this->intOf($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM document WHERE predecessor_id = CAST(:id AS UUID) '
            . "AND state IN ('received', 'scanning', 'ready')",
            ['id' => $predecessorId],
        ));
    }

    private function erase(string $documentId): void
    {
        $this->service(ApplyErasure::class)->apply(
            $documentId,
            Uuid::generate(),
            ErasureReason::UploadedInError->value,
        );
    }

    private function eraseSeries(string $seriesId): SeriesErasure
    {
        return $this->service(EraseDocument::class)
            ->eraseSeries($seriesId, ErasureReason::UploadedInError, Uuid::generate())
        ;
    }

    private function reopen(string $documentId): void
    {
        $this->service(RescanDocument::class)->rescan($documentId, RescanReason::FailureInvestigated, Uuid::generate());
    }

    /**
     * @param class-string<Throwable> $expected
     */
    private function assertRefusedWith(string $expected, callable $operation): void
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
