<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Scanning\RescanDocument;
use Erpify\Documents\Document\Application\Scanning\ScanDueDocuments;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Exception\DocumentNotReadable;
use Erpify\Documents\Document\Domain\Exception\DocumentNotRescannable;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Scanning\RescanReason;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportDocumentScansHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportDocumentScansMessage;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Scanner\FakeMalwareScanner;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The readability guard, the operator's re-scan and the scan's alarms, against real Postgres.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per row of the matrix
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the use cases, the doubles and the registries they read
 */
#[CoversClass(RescanDocument::class)]
#[CoversClass(ReportDocumentScansHandler::class)]
#[CoversClass(Document::class)]
final class ScanReportAndRescanFunctionalTest extends KernelTestCase
{
    use PlantsScannableDocuments;

    private TestHandler $log;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        FakeMalwareScanner::reset();
        $this->emptyDocumentTables();
        $this->log = new TestHandler();
    }

    protected function tearDown(): void
    {
        $this->discardScannableObjects();
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('provideADocumentIsNotReadableInAnyStateButReadyCases')]
    public function aDocumentIsNotReadableInAnyStateButReady(DocumentState $state): void
    {
        $id = $this->plantDocument(Uuid::generate(), $state);
        $document = $this->service(DocumentRepository::class)->findById($id);
        $this->assertInstanceOf(Document::class, $document);

        $this->expectException(DocumentNotReadable::class);
        $document->assertReadable();
    }

    /**
     * @return iterable<string, array{DocumentState}>
     */
    public static function provideADocumentIsNotReadableInAnyStateButReadyCases(): iterable
    {
        foreach (DocumentState::cases() as $state) {
            if (DocumentState::Ready !== $state) {
                yield $state->value => [$state];
            }
        }
    }

    #[Test]
    public function aReadyDocumentIsReadable(): void
    {
        $id = $this->plantDocument(Uuid::generate(), DocumentState::Ready);
        $document = $this->service(DocumentRepository::class)->findById($id);
        $this->assertInstanceOf(Document::class, $document);

        $document->assertReadable();
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aFailedScanIsReopenedWithBothCountersResetTheEpochAdvancedAndTheOperatorInCustody(): void
    {
        $operator = Uuid::generate();
        $id = $this->plantDocument(Uuid::generate(), DocumentState::ScanFailed);
        $this->plantCustody($id, 1, Uuid::generate());
        $this->connection()->executeStatement(
            'UPDATE document SET scan_attempts = 3, scan_outage_returns = 4, lease_epoch = 7, '
            . "first_claim_recorded = TRUE, next_scan_at = NOW() + INTERVAL '1 hour' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );

        $this->service(RescanDocument::class)->rescan($id, RescanReason::ScannerOutageResolved, $operator);

        $row = $this->scanRow($id);
        $this->assertSame('received', $row['state']);
        $this->assertSame([0, 0, 8, false], [$row['attempts'], $row['outage'], $row['epoch'], $row['recorded']]);
        $this->assertLessThanOrEqual(0.0, $row['delay'], 'due now');
        $this->assertSame(['received', 'rescan_requested'], $this->custodyEvents($id));
        $this->assertSame([$operator], \array_slice($this->actorsOf($id), 1), "the operator is the row's actor");
    }

    #[Test]
    public function theCustodyRowOfTheDecisionCarriesTheReasonForEachReasonOfTheClosedList(): void
    {
        foreach (RescanReason::cases() as $reason) {
            $id = $this->plantDocument(Uuid::generate(), DocumentState::ScanFailed);
            $this->plantCustody($id, 1, Uuid::generate());

            $this->service(RescanDocument::class)->rescan($id, $reason, Uuid::generate());

            $stored = $this->connection()->fetchOne(
                'SELECT reason FROM document_custody WHERE document_id = CAST(:id AS UUID) AND seq = 2',
                ['id' => $id],
            );
            $this->assertSame($reason->value, $stored);
            $others = $this->connection()->fetchOne(
                'SELECT COUNT(*) FROM document_custody WHERE document_id = CAST(:id AS UUID) AND reason IS NOT NULL',
                ['id' => $id],
            );
            $this->assertSame(1, $this->intOf($others), 'only the decision carries a reason');
        }
    }

    #[Test]
    public function aReopenedDocumentIsClaimedAgainAndRecordsItsFirstClaimAgain(): void
    {
        $id = $this->fileScannableDocument('scanned twice');
        $this->connection()->executeStatement(
            "UPDATE document SET state = 'scan_failed', first_claim_recorded = TRUE WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );
        $this->service(RescanDocument::class)->rescan($id, RescanReason::FailureInvestigated, Uuid::generate());

        $this->service(ScanDueDocuments::class)->run();

        $this->assertSame('ready', $this->scanRow($id)['state']);
        $this->assertSame(
            ['received', 'rescan_requested', 'scan_claimed', 'scan_ready'],
            $this->custodyEvents($id),
        );
    }

    #[Test]
    #[DataProvider('provideOnlyAFailedScanIsReopenedAndAnyOtherStateChangesNothingCases')]
    public function onlyAFailedScanIsReopenedAndAnyOtherStateChangesNothing(DocumentState $state): void
    {
        $id = $this->plantDocument(Uuid::generate(), $state);
        $before = $this->scanRow($id);
        unset($before['delay']);

        try {
            $this->service(RescanDocument::class)->rescan($id, RescanReason::FailureInvestigated, Uuid::generate());
            $this->fail('a document that is not in ScanFailed cannot be reopened');
        } catch (DocumentNotRescannable) {
            $after = $this->scanRow($id);
            unset($after['delay']);
            $this->assertSame($before, $after);
            $this->assertSame([], $this->custodyEvents($id), 'no custody row for a refused decision');
        }
    }

    /**
     * @return iterable<string, array{DocumentState}>
     */
    public static function provideOnlyAFailedScanIsReopenedAndAnyOtherStateChangesNothingCases(): iterable
    {
        foreach (DocumentState::cases() as $state) {
            if (DocumentState::ScanFailed !== $state) {
                yield $state->value => [$state];
            }
        }
    }

    #[Test]
    public function anAbsentDocumentIsNotFound(): void
    {
        $this->expectException(DocumentNotFound::class);

        $this->service(RescanDocument::class)->rescan(
            Uuid::generate(),
            RescanReason::FailureInvestigated,
            Uuid::generate(),
        );
    }

    #[Test]
    public function aDocumentReturnedByAnAbsentScannerFiveTimesIsNamedByTheAlarmAndOneAtFourIsNot(): void
    {
        $alarmed = $this->plantDocument(Uuid::generate());
        $quiet = $this->plantDocument(Uuid::generate());
        $this->plantCustody($alarmed, 1, Uuid::generate());
        $this->plantCustody($quiet, 1, Uuid::generate());
        $this->connection()->executeStatement(
            'UPDATE document SET scan_outage_returns = 5 WHERE id = CAST(:id AS UUID)',
            ['id' => $alarmed],
        );
        $this->connection()->executeStatement(
            'UPDATE document SET scan_outage_returns = 4 WHERE id = CAST(:id AS UUID)',
            ['id' => $quiet],
        );

        $this->report();

        $line = $this->lineAbout('returned by an absent scanner');
        $this->assertNotNull($line);
        $this->assertSame([$alarmed], $line['documentIds']);
        $this->assertSame(1, $line['count']);
    }

    #[Test]
    public function aDocumentStuckBeyondTheBoundIsNamedMeasuredFromItsLastCustodyEvent(): void
    {
        $stuck = $this->plantDocument(Uuid::generate());
        $fresh = $this->plantDocument(Uuid::generate());
        $this->plantCustody($stuck, 1, Uuid::generate());
        $this->plantCustody($fresh, 1, Uuid::generate());
        $this->connection()->executeStatement(
            "UPDATE document_custody SET created_at = NOW() - INTERVAL '11 hours' "
            . 'WHERE document_id = CAST(:id AS UUID)',
            ['id' => $stuck],
        );

        $this->report();

        $line = $this->lineAbout('stuck before a scan verdict');
        $this->assertNotNull($line);
        $this->assertSame([$stuck], $line['documentIds']);
    }

    #[Test]
    public function aReopenedOldDocumentDoesNotAlarmAtOnceBecauseTheDecisionIsAFreshCustodyEvent(): void
    {
        $id = $this->plantDocument(Uuid::generate(), DocumentState::ScanFailed);
        $this->plantCustody($id, 1, Uuid::generate());
        $this->connection()->executeStatement(
            "UPDATE document_custody SET created_at = NOW() - INTERVAL '3 days' WHERE document_id = CAST(:id AS UUID)",
            ['id' => $id],
        );
        $this->report();
        $this->assertNotNull($this->lineAbout('waiting for an operator'), 'an old ScanFailed waits for an operator');

        $this->log->clear();
        $this->service(RescanDocument::class)->rescan($id, RescanReason::FailureInvestigated, Uuid::generate());
        $this->connection()->executeStatement(
            "UPDATE document SET scan_outage_returns = 0, state = 'received' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );
        $this->report();

        $this->assertNull($this->lineAbout('stuck before a scan verdict'));
    }

    #[Test]
    public function quarantinedAndFailedDocumentsOlderThanADayWaitForAnOperatorAndDeterministicFailuresAreNamed(): void
    {
        $quarantined = $this->plantDocument(Uuid::generate(), DocumentState::Quarantined);
        $failed = $this->plantDocument(Uuid::generate(), DocumentState::ScanFailed);
        $capped = $this->plantDocument(Uuid::generate(), DocumentState::ScanFailed);

        foreach ([$quarantined, $failed, $capped] as $id) {
            $this->plantCustody($id, 1, Uuid::generate());
            $this->connection()->executeStatement(
                "UPDATE document_custody SET created_at = NOW() - INTERVAL '25 hours' "
                . 'WHERE document_id = CAST(:id AS UUID)',
                ['id' => $id],
            );
        }

        $this->connection()->executeStatement(
            'UPDATE document SET scan_attempts = 3 WHERE id = CAST(:id AS UUID)',
            ['id' => $capped],
        );

        $this->report();

        $waiting = $this->lineAbout('waiting for an operator');
        $this->assertNotNull($waiting);
        $this->assertSame(3, $waiting['count']);
        $deterministic = $this->lineAbout('a retry would not change');
        $this->assertNotNull($deterministic);
        $this->assertSame([$failed], $deterministic['documentIds'], 'a capped failure is not a deterministic one');
    }

    #[Test]
    public function aCleanTableReportsNothing(): void
    {
        $this->plantDocument(Uuid::generate(), DocumentState::Ready);

        $this->report();

        $this->assertSame([], $this->log->getRecords());
    }

    private function report(): void
    {
        $handler = new ReportDocumentScansHandler($this->connection(), new Logger('observability', [$this->log]));
        $handler(new ReportDocumentScansMessage());
    }

    /**
     * @return array{count: int, documentIds: list<string>}|null
     */
    private function lineAbout(string $fragment): ?array
    {
        foreach ($this->log->getRecords() as $record) {
            if (\str_contains($record->message, $fragment)) {
                /** @var array{count: int, documentIds: list<string>} $context */
                $context = $record->context;

                return $context;
            }
        }

        return null;
    }
}
