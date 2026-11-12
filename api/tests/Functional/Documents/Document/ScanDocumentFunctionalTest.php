<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\DriverManager;
use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\Scanning\ExpireScanLeases;
use Erpify\Documents\Document\Application\Scanning\ScanDocument;
use Erpify\Documents\Document\Application\Scanning\ScanDueDocuments;
use Erpify\Documents\Document\Application\Scanning\ScannerHealth;
use Erpify\Documents\Document\Application\Scanning\ScanResult;
use Erpify\Documents\Document\Application\Scanning\SweepCappedDocuments;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Exception\DocumentNotReadable;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\MalwareScanner;
use Erpify\Documents\Document\Domain\Scanning\ReturnOutcome;
use Erpify\Documents\Document\Domain\Scanning\ScanClaim;
use Erpify\Documents\Document\Domain\ScanPolicy;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Infrastructure\Messenger\ScanDocumentHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\ScanDocumentMessage;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentScanLeases;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Scanner\FakeMalwareScanner;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * The scan end to end against real Postgres, the real document volume and the real sealer, with the scanner a double:
 * one test per row of the I/O matrix of doc-4. Every transition is read back through DBAL, and the custody rows are
 * counted, because "no custody row" is half of what most of these assert.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods") one test per row of the matrix
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") the same
 */
#[CoversClass(ScanDocument::class)]
#[CoversClass(ScanDueDocuments::class)]
#[CoversClass(ExpireScanLeases::class)]
#[CoversClass(SweepCappedDocuments::class)]
#[CoversClass(DbalDocumentScanLeases::class)]
#[CoversClass(ScanDocumentHandler::class)]
final class ScanDocumentFunctionalTest extends KernelTestCase
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
        FakeMalwareScanner::reset();
        $this->discardScannableObjects();
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    public function aCleanFileIsClaimedStreamedAndReadyWithExactlyTwoNewCustodyRows(): void
    {
        $id = $this->fileScannableDocument(\random_bytes(200_000));

        $result = $this->scanner()->scan($id, $this->health());

        $this->assertSame(ScanResult::Ready, $result);
        $row = $this->scanRow($id);
        $this->assertSame('ready', $row['state']);
        $this->assertSame(1, $row['epoch']);
        $this->assertSame(0, $row['attempts'] + $row['outage']);
        $this->assertFalse($row['claimed'], 'a settled row holds no claim');
        $this->assertSame(['received', 'scan_claimed', 'scan_ready'], $this->custodyEvents($id));
        $this->assertSame(200_000, FakeMalwareScanner::bytesSeen());

        $document = $this->service(DocumentRepository::class)->findById($id);
        $this->assertInstanceOf(Document::class, $document);
        $document->assertReadable();
    }

    #[Test]
    public function theScanActsAsTheSystemAndNeverNamesAPerson(): void
    {
        $id = $this->fileScannableDocument('clean content');

        $this->scanner()->scan($id, $this->health());

        $actors = $this->connection()->fetchFirstColumn(
            'SELECT actor_id FROM document_custody WHERE document_id = CAST(:id AS UUID) ORDER BY seq',
            ['id' => $id],
        );
        $this->assertNotNull(\array_first($actors), 'the receipt names the uploader');
        $this->assertSame([null, null], \array_slice($actors, 1), 'the claim and the verdict name the system');
    }

    #[Test]
    public function anEicarStreamIsQuarantinedWithItsVerdictInCustody(): void
    {
        $id = $this->fileScannableDocument('prefix ' . FakeMalwareScanner::EICAR . ' suffix');

        $this->assertSame(ScanResult::Quarantined, $this->scanner()->scan($id, $this->health()));

        $this->assertSame('quarantined', $this->scanRow($id)['state']);
        $this->assertSame(['received', 'scan_claimed', 'scan_quarantined'], $this->custodyEvents($id));
        $document = $this->service(DocumentRepository::class)->findById($id);
        $this->assertInstanceOf(Document::class, $document);
        $this->expectException(DocumentNotReadable::class);
        $document->assertReadable();
    }

    #[Test]
    public function aLimitAnswerIsScanFailedWithAnAlarmAndNeverReady(): void
    {
        FakeMalwareScanner::answerLimit();
        $id = $this->fileScannableDocument('over a limit');

        $this->assertSame(ScanResult::Failed, $this->scanner()->scan($id, $this->health()));

        $row = $this->scanRow($id);
        $this->assertSame('scan_failed', $row['state']);
        $this->assertSame(0, $row['attempts'] + $row['outage'], 'a deterministic failure retries nothing');
        $this->assertSame(['received', 'scan_claimed', 'scan_failed'], $this->custodyEvents($id));
        $this->assertTrue($this->log->hasErrorThatContains('will not be retried'));
        $this->assertSame('scanner-limit', $this->alarmContext()['reason'] ?? null);
        $this->assertSame($id, $this->alarmContext()['documentId'] ?? null);
    }

    #[Test]
    public function aScannerThatFailsItsProbeLeavesTheRowUntouched(): void
    {
        FakeMalwareScanner::goDown();
        $id = $this->fileScannableDocument('waiting');
        $before = $this->scanRow($id);

        $this->assertSame(0, $this->sweep()->run());

        $this->assertSame($this->withoutDelay($before), $this->withoutDelay($this->scanRow($id)));
        $this->assertSame(['received'], $this->custodyEvents($id), 'no claim, no epoch, no attempt, no custody row');
        $this->assertSame(0, FakeMalwareScanner::scans());
    }

    #[Test]
    public function aConnectThatFailsAfterTheClaimIsAnOutageReturnNotAnAttempt(): void
    {
        FakeMalwareScanner::failConnectOnly();
        $id = $this->fileScannableDocument('waiting');

        $this->sweep()->run();

        $row = $this->scanRow($id);
        $this->assertSame('received', $row['state']);
        $this->assertSame(1, $row['epoch']);
        $this->assertSame(0, $row['attempts']);
        $this->assertSame(1, $row['outage']);
        $this->assertFalse($row['streaming']);
        $this->assertEqualsWithDelta(60.0, $row['delay'], 60 * ScanPolicy::JITTER + 2, 'first ladder entry');
        $this->assertSame(['received', 'scan_claimed'], $this->custodyEvents($id), 'the return writes no row');
        $this->assertSame(0, $this->sweep()->run(), 'not due again until the ladder entry passes');
    }

    #[Test]
    public function aResetMidStreamWithTheScannerUpBurnsAnAttemptAndTheThirdEndsInScanFailed(): void
    {
        FakeMalwareScanner::resetAfterFirstByte();
        $id = $this->fileScannableDocument(\random_bytes(100_000));

        $this->scanner()->scan($id, $this->health());
        $row = $this->scanRow($id);
        $this->assertSame('received', $row['state']);
        $this->assertSame(1, $row['attempts']);
        $this->assertSame(0, $row['outage']);
        $this->assertFalse($row['streaming'], 'the mark is cleared on every return');
        $this->assertEqualsWithDelta(60.0, $row['delay'], 60 * ScanPolicy::JITTER + 2);

        $this->makeDue($id);
        $this->scanner()->scan($id, $this->health());
        $row = $this->scanRow($id);
        $this->assertSame(2, $row['attempts']);
        $this->assertEqualsWithDelta(300.0, $row['delay'], 300 * ScanPolicy::JITTER + 2, 'second counted entry');

        $this->makeDue($id);
        $this->assertSame(ScanResult::Returned, $this->scanner()->scan($id, $this->health()));
        $row = $this->scanRow($id);
        $this->assertSame('scan_failed', $row['state'], 'the cap is reached in the same statement as the return');
        $this->assertSame(3, $row['attempts']);
        $this->assertSame(
            ['received', 'scan_claimed', 'scan_failed'],
            $this->custodyEvents($id),
            'the first claim once, no row for a re-claim or a return, one for the cap verdict',
        );
        $this->assertSame(3, $row['epoch'], 'every claim took an epoch');
    }

    #[Test]
    public function aResetFoundByAReprobeThatSeesTheScannerDownIsAnOutageReturn(): void
    {
        FakeMalwareScanner::resetAfterFirstByte();
        $id = $this->fileScannableDocument(\random_bytes(100_000));

        $this->scanner()->scan($id, new ScannerHealth($this->downScanner()));

        $row = $this->scanRow($id);
        $this->assertSame(0, $row['attempts']);
        $this->assertSame(1, $row['outage']);
        $this->assertEqualsWithDelta(60.0, $row['delay'], 60 * ScanPolicy::JITTER + 2);
    }

    #[Test]
    public function outageReturnsWalkTheWholeLadderAndStayAtTheLastEntry(): void
    {
        $expected = [60, 300, 900, 3600, 3600];
        $id = $this->fileScannableDocument('waiting');
        $leases = $this->service(DocumentScanLeases::class);

        foreach ($expected as $index => $seconds) {
            $this->makeDue($id);
            $claim = $leases->claim($id);
            $this->assertInstanceOf(ScanClaim::class, $claim);
            $this->assertSame(ReturnOutcome::BackedOff, $leases->returnToReceived($id, $claim->epoch, false, false));
            $this->assertEqualsWithDelta(
                $seconds,
                $this->scanRow($id)['delay'],
                $seconds * ScanPolicy::JITTER + 2,
                'outage return ' . ($index + 1),
            );
        }

        $this->assertSame(5, $this->scanRow($id)['outage']);
    }

    #[Test]
    public function theTenthOutageReturnEndsInScanFailedWithItsCustodyRow(): void
    {
        $id = $this->fileScannableDocument('waiting');
        $this->connection()->executeStatement(
            'UPDATE document SET scan_outage_returns = 9 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        FakeMalwareScanner::failConnectOnly();

        $this->scanner()->scan($id, $this->health());

        $this->assertSame('scan_failed', $this->scanRow($id)['state']);
        $this->assertSame(10, $this->scanRow($id)['outage']);
        $this->assertSame(['received', 'scan_claimed', 'scan_failed'], $this->custodyEvents($id));
    }

    #[Test]
    public function aWorkerThatDiedIsTakenBackWhenItsLeaseLapsesAndCountedAsAnAttempt(): void
    {
        $id = $this->fileScannableDocument('claimed then lost');
        $claim = $this->service(DocumentScanLeases::class)->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);
        $this->connection()->executeStatement(
            "UPDATE document SET scan_streaming_started_at = NOW(), lease_expires_at = NOW() - INTERVAL '1 second' "
            . 'WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );

        $this->assertSame(1, $this->expiry($this->scannerThatIsUp())->run());

        $row = $this->scanRow($id);
        $this->assertSame('received', $row['state']);
        $this->assertSame(1, $row['attempts']);
        $this->assertFalse($row['streaming']);
    }

    #[Test]
    public function aLeaseStillLiveIsNotTakenBack(): void
    {
        $id = $this->fileScannableDocument('claimed');
        $this->assertInstanceOf(ScanClaim::class, $this->service(DocumentScanLeases::class)->claim($id));

        $this->assertSame(0, $this->expiry($this->scannerThatIsUp())->run());
        $this->assertSame('scanning', $this->scanRow($id)['state']);
    }

    #[Test]
    public function aLapsedLeaseOfAWorkerWithoutStreamingAndAScannerDownIsAnOutageReturn(): void
    {
        $id = $this->fileScannableDocument('claimed then lost');
        $this->assertInstanceOf(ScanClaim::class, $this->service(DocumentScanLeases::class)->claim($id));
        $this->connection()->executeStatement(
            "UPDATE document SET lease_expires_at = NOW() - INTERVAL '1 second' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );

        $this->expiry($this->downScanner())->run();

        $row = $this->scanRow($id);
        $this->assertSame(0, $row['attempts']);
        $this->assertSame(1, $row['outage']);
    }

    #[Test]
    public function theLeaseExpiryCarriesTheStaleEpochSoAWorkerThatHeartbeatedInTimeWins(): void
    {
        $id = $this->fileScannableDocument('claimed');
        $leases = $this->service(DocumentScanLeases::class);
        $claim = $leases->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);

        $this->assertSame(
            ReturnOutcome::Lost,
            $leases->returnToReceived($id, $claim->epoch, false, true),
            'the lease is live, so the expiry form matches nothing',
        );
        $this->assertSame(
            ReturnOutcome::Lost,
            $leases->returnToReceived($id, $claim->epoch + 1, false, false),
            'another epoch matches nothing',
        );
    }

    #[Test]
    public function aHeartbeatThatFindsTheRowErasedAbortsWithNothingCounted(): void
    {
        $id = $this->fileScannableDocument(\random_bytes(300_000));
        $scanner = $this->mutatingScanner(fn (): int|string => $this->connection()->executeStatement(
            "UPDATE document SET state = 'erased' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        ));

        $result = $this->scanner($scanner, heartbeatEvery: 0)->scan($id, $this->health());

        $this->assertSame(ScanResult::Aborted, $result);
        $row = $this->scanRow($id);
        $this->assertSame('erased', $row['state']);
        $this->assertSame(0, $row['attempts'] + $row['outage']);
        $this->assertSame(['received', 'scan_claimed'], $this->custodyEvents($id), 'no verdict row after an erasure');
    }

    #[Test]
    public function aHeartbeatThatFindsTheEpochSupersededAbortsWithNothingCounted(): void
    {
        $id = $this->fileScannableDocument(\random_bytes(300_000));
        $scanner = $this->mutatingScanner(fn (): int|string => $this->connection()->executeStatement(
            'UPDATE document SET lease_epoch = lease_epoch + 1 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        ));

        $this->assertSame(ScanResult::Aborted, $this->scanner($scanner, heartbeatEvery: 0)->scan($id, $this->health()));

        $row = $this->scanRow($id);
        $this->assertSame('scanning', $row['state'], 'the new claim is not disturbed');
        $this->assertSame(2, $row['epoch']);
        $this->assertSame(0, $row['attempts'] + $row['outage']);
    }

    #[Test]
    public function aDestroyedKeyMidScanAbortsWithNothingCounted(): void
    {
        $id = $this->fileScannableDocument('sealed under a key about to be destroyed');
        $document = $this->service(DocumentRepository::class)->findById($id);
        $this->assertInstanceOf(Document::class, $document);
        $this->service(TransactionManager::class)->transactional(
            fn () => $this->service(Keystore::class)->destroy(
                EncryptionScopeId::forDocument($document->scopeId()),
                DestroyReason::Erasure,
            ),
        );

        $this->assertSame(ScanResult::Aborted, $this->scanner()->scan($id, $this->health()));

        $row = $this->scanRow($id);
        $this->assertSame(0, $row['attempts'] + $row['outage']);
        $this->assertSame(['received', 'scan_claimed'], $this->custodyEvents($id));
    }

    #[Test]
    public function anObjectThatFailsItsAuthenticationIsScanFailedWithAnAlarm(): void
    {
        $id = $this->fileScannableDocument(\random_bytes(100_000));
        $this->flipAByteOfTheFinalObject($id);

        $this->assertSame(ScanResult::Failed, $this->scanner()->scan($id, $this->health()));

        $this->assertSame('scan_failed', $this->scanRow($id)['state']);
        $this->assertSame('authentication-failed', $this->alarmContext()['reason'] ?? null);
        $this->assertSame(['received', 'scan_claimed', 'scan_failed'], $this->custodyEvents($id));
    }

    #[Test]
    public function aMissingFinalObjectIsScanFailedWithAnAlarm(): void
    {
        $id = $this->fileScannableDocument('about to vanish');
        $document = $this->service(DocumentRepository::class)->findById($id);
        $this->assertInstanceOf(Document::class, $document);
        $this->service(DocumentStorage::class)->delete(StorageArea::Final, $document->scopeId());

        $this->assertSame(ScanResult::Failed, $this->scanner()->scan($id, $this->health()));

        $this->assertSame('object-missing', $this->alarmContext()['reason'] ?? null);
        $this->assertSame('scan_failed', $this->scanRow($id)['state']);
    }

    #[Test]
    public function aVerdictAfterTheDeadlineLosesAndLeavesTheRowToTheLeaseExpiry(): void
    {
        $id = $this->fileScannableDocument('slow');
        $leases = $this->service(DocumentScanLeases::class);
        $claim = $leases->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);
        $this->connection()->executeStatement(
            "UPDATE document SET claimed_at = NOW() - INTERVAL '11 minutes' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );

        $this->assertFalse($leases->settle($id, $claim->epoch, DocumentState::Ready));
        $this->assertFalse($leases->heartbeat($id, $claim->epoch), 'a heartbeat cannot extend the deadline either');
        $this->assertFalse($leases->markStreaming($id, $claim->epoch));
        $this->assertSame('scanning', $this->scanRow($id)['state']);
        $this->assertSame(['received'], $this->custodyEvents($id));
    }

    #[Test]
    public function aHeartbeatNeverRenewsTheLeasePastTheDeadline(): void
    {
        $id = $this->fileScannableDocument('slow');
        $leases = $this->service(DocumentScanLeases::class);
        $claim = $leases->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);
        $this->connection()->executeStatement(
            "UPDATE document SET claimed_at = NOW() - INTERVAL '599 seconds' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );

        $this->assertTrue($leases->heartbeat($id, $claim->epoch));

        $beyondDeadline = $this->connection()->fetchOne(
            'SELECT lease_expires_at > claimed_at + make_interval(secs => :deadline) FROM document '
            . 'WHERE id = CAST(:id AS UUID)',
            ['id' => $id, 'deadline' => ScanPolicy::DEADLINE_SECONDS],
        );
        $this->assertFalse($beyondDeadline);
    }

    #[Test]
    public function aVerdictOnAnErasedRowMatchesNothingInEveryStatement(): void
    {
        $id = $this->plantDocument(Uuid::generate(), DocumentState::Erased);
        $this->connection()->executeStatement(
            'UPDATE document SET lease_epoch = 1, scan_attempts = 5, scan_outage_returns = 20, '
            . "claimed_at = NOW(), lease_expires_at = NOW() - INTERVAL '1 hour' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );
        $before = $this->withoutDelay($this->scanRow($id));
        $leases = $this->service(DocumentScanLeases::class);

        $this->assertFalse($leases->settle($id, 1, DocumentState::Ready));
        $this->assertFalse($leases->settle($id, 1, DocumentState::Quarantined));
        $this->assertFalse($leases->settle($id, 1, DocumentState::ScanFailed));
        $this->assertFalse($leases->heartbeat($id, 1));
        $this->assertFalse($leases->markStreaming($id, 1));
        $this->assertSame(ReturnOutcome::Lost, $leases->returnToReceived($id, 1, false, false));
        $this->assertSame(ReturnOutcome::Lost, $leases->returnToReceived($id, 1, true, true));
        $this->assertFalse($leases->failCapped($id));
        $this->assertFalse($leases->reopen($id));
        $this->assertNull($leases->claim($id));
        $this->assertSame($before, $this->withoutDelay($this->scanRow($id)));
    }

    #[Test]
    public function anUnpromotedRowIsNotClaimedAndNothingIsCounted(): void
    {
        $id = $this->fileUnpromotedDocument('not renamed yet');
        $leases = $this->service(DocumentScanLeases::class);

        $this->assertNotContains($id, $leases->dueCandidates(100));
        $this->assertNull($leases->claim($id));
        $this->assertSame(0, $this->sweep()->run());
        $row = $this->scanRow($id);
        $this->assertSame([0, 0, 0], [$row['epoch'], $row['attempts'], $row['outage']]);
        $this->assertSame(['received'], $this->custodyEvents($id));
    }

    #[Test]
    public function aRowAtACapIsNotClaimedAndTheCapSweepEndsItInScanFailedOnce(): void
    {
        $id = $this->fileScannableDocument('stranded by a lowered cap');
        $this->connection()->executeStatement(
            'UPDATE document SET scan_attempts = 3 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $leases = $this->service(DocumentScanLeases::class);
        $this->assertNull($leases->claim($id), 'the claim predicate refuses a counter at its cap');

        $sweep = new SweepCappedDocuments(
            $leases,
            $this->service(AppendCustody::class),
            $this->service(TransactionManager::class),
            new Logger('observability', [$this->log]),
        );

        $this->assertSame(1, $sweep->run());
        $this->assertSame(0, $sweep->run(), 'a duplicate tick finds nothing');
        $this->assertSame('scan_failed', $this->scanRow($id)['state']);
        $this->assertSame(['received', 'scan_failed'], $this->custodyEvents($id));
    }

    #[Test]
    public function twoClaimsOfOneRowHaveOneWinnerEvenWhenTheyRace(): void
    {
        $id = $this->fileScannableDocument('contended');
        $leases = $this->service(DocumentScanLeases::class);
        $holder = DriverManager::getConnection($this->connection()->getParams());
        $holder->beginTransaction();

        $rival = new DbalDocumentScanLeases($holder);

        try {
            $this->assertInstanceOf(ScanClaim::class, $rival->claim($id), 'the rival claims in an open transaction');

            $this->connection()->executeStatement("SET lock_timeout = '200ms'");

            try {
                $leases->claim($id);
                $this->fail('the second claim must wait for the row the rival holds');
            } catch (Throwable $throwable) {
                $this->assertStringContainsString(
                    'lock timeout',
                    $throwable->getMessage() . $throwable->getPrevious()?->getMessage(),
                );
            } finally {
                $this->connection()->executeStatement('SET lock_timeout = 0');
            }

            $holder->commit();
            $this->assertNull($leases->claim($id), 'the loser finds the row no longer claimable');
            $this->assertSame(1, $this->scanRow($id)['epoch']);
        } finally {
            if ($holder->isTransactionActive()) {
                $holder->rollBack();
            }

            $holder->close();
        }
    }

    #[Test]
    public function theWakeUpHandlerRunsOnePassOfTheSweep(): void
    {
        $id = $this->fileScannableDocument('woken');
        $handler = new ScanDocumentHandler($this->sweep());

        $handler(new ScanDocumentMessage($id));

        $this->assertSame('ready', $this->scanRow($id)['state']);
    }

    #[Test]
    public function aLostWakeUpChangesNothingBecauseTheNextPassFindsTheRow(): void
    {
        $id = $this->fileScannableDocument('never woken');

        $this->assertSame(1, $this->sweep()->run());
        $this->assertSame('ready', $this->scanRow($id)['state']);
        $this->assertSame(0, $this->sweep()->run(), 'a duplicate pass finds nothing due');
    }

    #[Test]
    public function aVerdictIsNeverRescannedOnceReady(): void
    {
        $id = $this->fileScannableDocument('final');
        $this->sweep()->run();
        $this->makeDue($id);

        $this->assertSame(0, $this->sweep()->run());
        $this->assertSame(1, $this->scanRow($id)['epoch']);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function withoutDelay(array $row): array
    {
        unset($row['delay']);

        return $row;
    }

    private function sweep(?MalwareScanner $scanner = null): ScanDueDocuments
    {
        $scanner ??= new FakeMalwareScanner();

        return new ScanDueDocuments(
            $this->service(DocumentScanLeases::class),
            $scanner,
            $this->scanner($scanner),
            new Logger('observability', [$this->log]),
        );
    }

    private function scanner(
        ?MalwareScanner $scanner = null,
        int $heartbeatEvery = ScanPolicy::HEARTBEAT_SECONDS,
    ): ScanDocument {
        return new ScanDocument(
            $this->service(DocumentScanLeases::class),
            $this->service(DocumentRepository::class),
            $this->service(DocumentStorage::class),
            $this->service(StreamEnvelopeEncryptor::class),
            $scanner ?? new FakeMalwareScanner(),
            $this->service(AppendCustody::class),
            $this->service(TransactionManager::class),
            new Logger('observability', [$this->log]),
            $heartbeatEvery,
        );
    }

    private function expiry(MalwareScanner $scanner): ExpireScanLeases
    {
        return new ExpireScanLeases(
            $this->service(DocumentScanLeases::class),
            $scanner,
            $this->service(AppendCustody::class),
            $this->service(TransactionManager::class),
            new Logger('observability', [$this->log]),
        );
    }

    private function health(): ScannerHealth
    {
        return new ScannerHealth(new FakeMalwareScanner());
    }

    private function downScanner(): MalwareScanner
    {
        return new ProbeOnlyScanner(false);
    }

    private function scannerThatIsUp(): MalwareScanner
    {
        return new ProbeOnlyScanner(true);
    }

    /**
     * A scanner that runs `$afterFirstChunk` once the first chunk has been accepted, then keeps accepting.
     *
     * @param callable(): mixed $afterFirstChunk
     */
    private function mutatingScanner(callable $afterFirstChunk): MalwareScanner
    {
        return new MutatingScanner($afterFirstChunk);
    }

    /**
     * @return array<string, mixed>
     */
    private function alarmContext(): array
    {
        foreach ($this->log->getRecords() as $record) {
            if (\str_contains($record->message, 'will not be retried')) {
                /** @var array<string, mixed> $context */
                $context = $record->context;

                return $context;
            }
        }

        return [];
    }

    private function flipAByteOfTheFinalObject(string $documentId): void
    {
        $document = $this->service(DocumentRepository::class)->findById($documentId);
        $this->assertInstanceOf(Document::class, $document);
        $scope = $document->scopeId();
        $root = self::getContainer()->getParameter('erpify.documents.storage_root');
        $this->assertIsString($root);
        $shard = \substr($scope, -4, 2) . '/' . \substr($scope, -2);
        $path = \sprintf('%s/%s/%s/%s', $root, StorageArea::Final->value, $shard, $scope);
        $bytes = \file_get_contents($path);
        $this->assertIsString($bytes);
        $middle = \intdiv(\strlen($bytes), 2);
        $bytes[$middle] = \chr(\ord($bytes[$middle]) ^ 0xFF);
        \file_put_contents($path, $bytes);
    }
}
