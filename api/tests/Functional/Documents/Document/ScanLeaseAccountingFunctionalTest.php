<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use DateTimeImmutable;
use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\Scanning\ExpireScanLeases;
use Erpify\Documents\Document\Application\Scanning\ScanDocument;
use Erpify\Documents\Document\Application\Scanning\ScanDueDocuments;
use Erpify\Documents\Document\Application\Scanning\ScannerHealth;
use Erpify\Documents\Document\Application\Scanning\ScanResult;
use Erpify\Documents\Document\Application\Scanning\ScanSession;
use Erpify\Documents\Document\Application\Scanning\SweepCappedDocuments;
use Erpify\Documents\Document\Application\Scanning\WriteCallbackStream;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\ScanClaim;
use Erpify\Documents\Document\Domain\Scanning\ScanInterrupted;
use Erpify\Documents\Document\Domain\Scanning\ScanLease;
use Erpify\Documents\Document\Domain\Scanning\ScannerUnavailable;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentScanLeases;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Double\Scanner\FakeMalwareScanner;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The corners of the scan's accounting that decide whether a row is lost, counted twice or left looping: a row that
 * changes under a scan after its last heartbeat, a lease that lapses on a row an abort left claimed, a cap reached by
 * the expiry sweep, a connect that fails after the probe passed, and the heartbeat's own clock.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per corner
 */
#[CoversClass(ScanDocument::class)]
#[CoversClass(ScanDueDocuments::class)]
#[CoversClass(ExpireScanLeases::class)]
#[CoversClass(SweepCappedDocuments::class)]
#[CoversClass(ScanSession::class)]
#[CoversClass(ScannerHealth::class)]
#[CoversClass(WriteCallbackStream::class)]
#[CoversClass(Document::class)]
#[CoversClass(DbalDocumentScanLeases::class)]
#[CoversClass(ScanClaim::class)]
#[CoversClass(ScanLease::class)]
#[CoversClass(ScannerUnavailable::class)]
#[CoversClass(ScanInterrupted::class)]
final class ScanLeaseAccountingFunctionalTest extends KernelTestCase
{
    use BuildsScanServices;
    use PlantsScannableDocuments;

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
    public function aRowErasedAfterTheLastChunkEndsAbortedWithNoVerdictRow(): void
    {
        $id = $this->fileScannableDocument('one chunk');
        $scanner = new MutatingScanner(fn (): int|string => $this->connection()->executeStatement(
            "UPDATE document SET state = 'erased' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        ));

        $this->assertSame(ScanResult::Aborted, $this->scanner($scanner)->scan($id, $this->health()));

        $this->assertSame('erased', $this->scanRow($id)['state']);
        $this->assertSame(['received', 'scan_claimed'], $this->custodyEvents($id));
    }

    #[Test]
    public function aRowReclaimedAfterTheLastChunkEndsAbortedAndTheNewClaimIsNotDisturbed(): void
    {
        $id = $this->fileScannableDocument('one chunk');
        $scanner = new MutatingScanner(fn (): int|string => $this->connection()->executeStatement(
            'UPDATE document SET lease_epoch = lease_epoch + 1 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        ));

        $this->assertSame(ScanResult::Aborted, $this->scanner($scanner)->scan($id, $this->health()));

        $row = $this->scanRow($id);
        $this->assertSame(['scanning', 2], [$row['state'], $row['epoch']]);
        $this->assertSame(['received', 'scan_claimed'], $this->custodyEvents($id));
    }

    #[Test]
    public function aReturnThatLosesItsEpochCountsNothingAndWritesNoCustodyRow(): void
    {
        $id = $this->fileScannableDocument('one chunk');
        $scanner = new MutatingScanner(fn (): int|string => $this->connection()->executeStatement(
            'UPDATE document SET lease_epoch = lease_epoch + 1 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        ), thenFail: ScanInterrupted::create());

        $this->assertSame(ScanResult::Aborted, $this->scanner($scanner)->scan($id, $this->health()));

        $row = $this->scanRow($id);
        $this->assertSame(0, $row['attempts'] + $row['outage']);
        $this->assertSame('scanning', $row['state']);
        $this->assertSame(['received', 'scan_claimed'], $this->custodyEvents($id));
    }

    #[Test]
    public function keepAliveDoesNotBeatBeforeTheIntervalAndExtendsTheLeaseAfterIt(): void
    {
        $id = $this->fileScannableDocument('waiting for a verdict');
        $leases = $this->service(DocumentScanLeases::class);
        $claim = $leases->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);
        $this->connection()->executeStatement(
            "UPDATE document SET lease_expires_at = NOW() + INTERVAL '10 seconds' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );
        $before = $this->leaseSeconds($id);
        $session = new ScanSession($leases, $id, $claim->epoch, 1);

        $this->assertTrue($session->keepAlive());
        $this->assertEqualsWithDelta($before, $this->leaseSeconds($id), 1.0, 'no beat before the interval');

        \sleep(2);

        $this->assertTrue($session->keepAlive(), 'the verdict wait beats once the interval has passed');
        $this->assertGreaterThan($before + 60, $this->leaseSeconds($id), 'the lease was renewed to about two minutes');
    }

    #[Test]
    public function aWriteMarksStreamingOnceAndOnlyBeatsOnceTheIntervalHasPassed(): void
    {
        $id = $this->fileScannableDocument('streaming');
        $leases = $this->service(DocumentScanLeases::class);
        $claim = $leases->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);
        $this->connection()->executeStatement(
            "UPDATE document SET lease_expires_at = NOW() + INTERVAL '10 seconds' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );
        $before = $this->leaseSeconds($id);
        $session = new ScanSession($leases, $id, $claim->epoch, 1);
        $sink = \fopen('php://memory', 'w+');
        $this->assertIsResource($sink);

        $this->assertTrue($session->accept($sink, 'a'));
        $this->assertTrue($this->scanRow($id)['streaming'], 'the first write marks streaming');
        $this->assertTrue($session->accept($sink, 'b'));
        $this->assertEqualsWithDelta($before, $this->leaseSeconds($id), 1.0, 'a second write inside the interval');

        \sleep(2);
        $this->assertTrue($session->accept($sink, 'c'));
        $this->assertGreaterThan($before + 60, $this->leaseSeconds($id));
        \fclose($sink);
    }

    #[Test]
    public function theExpiryEndsADocumentInScanFailedWithItsCustodyRowWhenTheThirdAttemptIsCounted(): void
    {
        $id = $this->fileScannableDocument('dies for the third time');
        $this->connection()->executeStatement(
            'UPDATE document SET scan_attempts = 2 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $this->assertInstanceOf(ScanClaim::class, $this->service(DocumentScanLeases::class)->claim($id));
        $this->connection()->executeStatement(
            "UPDATE document SET scan_streaming_started_at = NOW(), lease_expires_at = NOW() - INTERVAL '1 second' "
            . 'WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );

        $this->assertSame(1, $this->expiry(new ProbeOnlyScanner(true))->run());

        $this->assertSame(['scan_failed', 3], [$this->scanRow($id)['state'], $this->scanRow($id)['attempts']]);
        $this->assertSame(['received', 'scan_failed'], $this->custodyEvents($id));
        $this->assertTrue($this->log->hasErrorThatContains('will not be retried'));
    }

    #[Test]
    public function theExpiryEndsADocumentInScanFailedWithItsCustodyRowAtTheTenthOutageReturn(): void
    {
        $id = $this->fileScannableDocument('the scanner has been away');
        $this->connection()->executeStatement(
            'UPDATE document SET scan_outage_returns = 9 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $this->assertInstanceOf(ScanClaim::class, $this->service(DocumentScanLeases::class)->claim($id));
        $this->lapseTheLease($id);

        $this->assertSame(1, $this->expiry(new ProbeOnlyScanner(false))->run());

        $row = $this->scanRow($id);
        $this->assertSame(['scan_failed', 10, 0], [$row['state'], $row['outage'], $row['attempts']]);
        $this->assertSame(['received', 'scan_failed'], $this->custodyEvents($id));
    }

    #[Test]
    public function theCapSweepEndsARowAtTheOutageCapNotOnlyAtTheAttemptCap(): void
    {
        $id = $this->fileScannableDocument('stranded by a lowered outage cap');
        $this->connection()->executeStatement(
            'UPDATE document SET scan_outage_returns = 10 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $sweep = new SweepCappedDocuments(
            $this->service(DocumentScanLeases::class),
            $this->service(AppendCustody::class),
            $this->service(TransactionManager::class),
            new Logger('observability', [$this->log]),
        );

        $this->assertSame(1, $sweep->run());

        $this->assertSame('scan_failed', $this->scanRow($id)['state']);
        $this->assertSame(['received', 'scan_failed'], $this->custodyEvents($id));
    }

    #[Test]
    public function aClaimLeftLiveByADestroyedKeyIsCountedWhenItsLeaseLapsesOnARowThatIsNotErased(): void
    {
        $id = $this->abortedByADestroyedKey();
        $this->lapseTheLease($id);

        $this->assertSame(1, $this->expiry(new ProbeOnlyScanner(true))->run());

        $row = $this->scanRow($id);
        $this->assertSame(['received', 0, 1], [$row['state'], $row['attempts'], $row['outage']], 'before streaming');
        $this->assertSame(['received', 'scan_claimed'], $this->custodyEvents($id));
    }

    #[Test]
    public function aClaimLeftLiveByADestroyedKeyIsLeftUntouchedWhenTheRowIsErasedByTheTimeItsLeaseLapses(): void
    {
        $id = $this->abortedByADestroyedKey();
        $this->connection()->executeStatement(
            "UPDATE document SET state = 'erased' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );
        $this->lapseTheLease($id);
        $before = $this->scanRow($id);
        unset($before['delay']);

        $this->assertSame(0, $this->expiry(new ProbeOnlyScanner(true))->run());

        $after = $this->scanRow($id);
        unset($after['delay']);
        $this->assertSame($before, $after);
        $this->assertSame('erased', $after['state']);
        $this->assertSame(['received', 'scan_claimed'], $this->custodyEvents($id));
    }

    #[Test]
    public function aConnectThatFailsAfterTheProbePassedStopsTheRunFromClaimingTheNextDocument(): void
    {
        FakeMalwareScanner::failConnectOnly();
        $first = $this->fileScannableDocument('first');
        $second = $this->fileScannableDocument('second');

        $this->sweep()->run();

        $epochs = [$this->scanRow($first)['epoch'], $this->scanRow($second)['epoch']];
        \sort($epochs);
        $this->assertSame([0, 1], $epochs, 'only one document was claimed and burnt an outage return');
        $this->assertSame(1, $this->scanRow($first)['outage'] + $this->scanRow($second)['outage']);
    }

    #[Test]
    public function theSnapshotReadsTheScanColumnsTheCompareAndSetsWrote(): void
    {
        $id = $this->fileScannableDocument('hydrated');
        $leases = $this->service(DocumentScanLeases::class);
        $documents = $this->service(DocumentRepository::class);

        $claim = $leases->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);
        $claimed = $documents->findById($id);
        $this->assertInstanceOf(Document::class, $claimed);
        $this->assertSame([1, true, 0, 0], [
            $claimed->leaseEpoch(),
            $claimed->firstClaimRecorded(),
            $claimed->scanAttempts(),
            $claimed->scanOutageReturns(),
        ]);
        $this->assertNull($claimed->scanStreamingStartedAt(), 'cleared by the claim');
        $this->assertInstanceOf(DateTimeImmutable::class, $claimed->claimedAt());
        $this->assertGreaterThan($claimed->claimedAt(), $claimed->leaseExpiresAt());

        $this->assertTrue($leases->markStreaming($id, $claim->epoch));
        $streaming = $documents->findById($id);
        $this->assertInstanceOf(Document::class, $streaming);
        $this->assertInstanceOf(DateTimeImmutable::class, $streaming->scanStreamingStartedAt());

        $leases->returnToReceived($id, $claim->epoch, false, false);
        $returned = $documents->findById($id);
        $this->assertInstanceOf(Document::class, $returned);
        $this->assertSame([1, 0], [$returned->scanAttempts(), $returned->scanOutageReturns()]);
        $this->assertNull($returned->claimedAt(), 'cleared by the return');
        $this->assertNull($returned->leaseExpiresAt());
        $this->assertNull($returned->scanStreamingStartedAt());
    }

    private function abortedByADestroyedKey(): string
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
        $this->assertSame('scanning', $this->scanRow($id)['state'], 'the abort leaves the claim live');

        return $id;
    }

    private function leaseSeconds(string $documentId): float
    {
        $seconds = $this->connection()->fetchOne(
            'SELECT EXTRACT(EPOCH FROM (lease_expires_at - NOW())) FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $documentId],
        );
        $this->assertIsNumeric($seconds);

        return (float) $seconds;
    }
}
