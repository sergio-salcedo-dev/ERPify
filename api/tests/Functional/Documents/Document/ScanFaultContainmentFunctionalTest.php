<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\Scanning\ExpireScanLeases;
use Erpify\Documents\Document\Application\Scanning\ScanDocument;
use Erpify\Documents\Document\Application\Scanning\ScanDueDocuments;
use Erpify\Documents\Document\Application\Scanning\ScannerHealth;
use Erpify\Documents\Document\Application\Scanning\ScanResult;
use Erpify\Documents\Document\Application\Scanning\ScanSession;
use Erpify\Documents\Document\Application\Scanning\SweepCappedDocuments;
use Erpify\Documents\Document\Application\Scanning\WriteCallbackStream;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\ScanClaim;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentScanLeases;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Double\Scanner\FakeMalwareScanner;
use InvalidArgumentException;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the scan does when something it depends on fails rather than answers: a database that drops during one
 * transition, a scanner that raises something no accounting names, a verdict the clock refuses after an
 * authentication failure, and the session's own refusals. Each sweep logs the failing item by its id and goes on;
 * a fault is never turned into a verdict.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per fault
 */
#[CoversClass(ScanDocument::class)]
#[CoversClass(ScanDueDocuments::class)]
#[CoversClass(ExpireScanLeases::class)]
#[CoversClass(SweepCappedDocuments::class)]
#[CoversClass(ScanSession::class)]
#[CoversClass(ScannerHealth::class)]
#[CoversClass(WriteCallbackStream::class)]
#[CoversClass(DbalDocumentScanLeases::class)]
#[CoversClass(ScanClaim::class)]
final class ScanFaultContainmentFunctionalTest extends KernelTestCase
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
    public function aDatabaseFaultWhileMarkingStreamingPropagatesOutOfTheScanAndIsNeitherAVerdictNorACount(): void
    {
        $id = $this->fileScannableDocument('the database drops mid-claim');
        $leases = $this->faulting('markStreaming');

        try {
            $this->scanDocumentOver($leases)->scan($id, $this->health());
            $this->fail('a database fault must reach the caller');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('markStreaming', $runtimeException->getMessage());
        }

        $row = $this->scanRow($id);
        $this->assertSame(['scanning', 1, 0, 0], [$row['state'], $row['epoch'], $row['attempts'], $row['outage']]);
        $this->assertSame(['received', 'scan_claimed'], $this->custodyEvents($id));
    }

    #[Test]
    public function theSweepLogsADocumentWhoseScanFaultedByItsIdAndGoesOnToTheNext(): void
    {
        $first = $this->fileScannableDocument('first');
        $second = $this->fileScannableDocument('second');
        $leases = $this->faulting('markStreaming');
        $sweep = new ScanDueDocuments(
            $leases,
            new FakeMalwareScanner(),
            $this->scanDocumentOver($leases),
            new Logger('observability', [$this->log]),
        );

        $this->assertSame(0, $sweep->run());

        $this->assertSame([1, 1], [$this->scanRow($first)['epoch'], $this->scanRow($second)['epoch']]);
        $faults = \array_filter(
            $this->log->getRecords(),
            static fn (\Monolog\LogRecord $record): bool => \str_contains($record->message, 'the batch goes on'),
        );
        $this->assertCount(2, $faults, 'one line per failing document, not one for the pass');
        $this->assertSame(RuntimeException::class, \array_first($faults)->context['exceptionClass'] ?? null);
    }

    #[Test]
    public function aFailureNoAccountingNamesPropagatesAndLeavesTheClaimLive(): void
    {
        $id = $this->fileScannableDocument('a defect of ours');
        $scanner = new MutatingScanner(static fn (): null => null, thenFail: new LogicException('a defect'));

        try {
            $this->scanner($scanner)->scan($id, $this->health());
            $this->fail('a defect must not be turned into a verdict or a return');
        } catch (LogicException $logicException) {
            $this->assertSame('a defect', $logicException->getMessage());
        }

        $this->assertSame('scanning', $this->scanRow($id)['state']);
        $this->assertSame(['received', 'scan_claimed'], $this->custodyEvents($id));
    }

    #[Test]
    public function aFailedAuthenticationWhoseVerdictTheEpochRefusesIsAnAbortAndWritesNoCustodyRow(): void
    {
        $id = $this->fileScannableDocument(\random_bytes(200_000));
        $this->flipAByteOfTheFinalObject($id, fromTheEnd: 5);
        $scanner = new MutatingScanner(fn (): int|string => $this->connection()->executeStatement(
            'UPDATE document SET lease_epoch = lease_epoch + 1 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        ));

        $this->assertSame(ScanResult::Aborted, $this->scanner($scanner)->scan($id, $this->health()));

        $this->assertSame(['scanning', 2], [$this->scanRow($id)['state'], $this->scanRow($id)['epoch']]);
        $this->assertSame(['received', 'scan_claimed'], $this->custodyEvents($id));
        $this->assertFalse($this->log->hasErrorThatContains('will not be retried'), 'a refused verdict is no alarm');
    }

    #[Test]
    public function theExpirySweepLogsALeaseItCouldNotTakeBackAndLeavesTheRowForTheNextTick(): void
    {
        $id = $this->fileScannableDocument('lapsed during an outage of the database');
        $this->assertInstanceOf(ScanClaim::class, $this->service(DocumentScanLeases::class)->claim($id));
        $this->lapseTheLease($id);
        $expiry = new ExpireScanLeases(
            $this->faulting('returnToReceived'),
            new ProbeOnlyScanner(true),
            $this->service(AppendCustody::class),
            $this->service(TransactionManager::class),
            new Logger('observability', [$this->log]),
        );

        $this->assertSame(0, $expiry->run());

        $this->assertSame('scanning', $this->scanRow($id)['state']);
        $this->assertTrue($this->log->hasErrorThatContains('could not be taken back'));
        $this->assertSame(1, $this->expiry(new ProbeOnlyScanner(true))->run(), 'the next tick finds it still lapsed');
    }

    #[Test]
    public function theCapSweepLogsARowItCouldNotEndAndLeavesItReceived(): void
    {
        $id = $this->fileScannableDocument('stranded');
        $this->connection()->executeStatement(
            'UPDATE document SET scan_attempts = 3 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $sweep = new SweepCappedDocuments(
            $this->faulting('failCapped'),
            $this->service(AppendCustody::class),
            $this->service(TransactionManager::class),
            new Logger('observability', [$this->log]),
        );

        $this->assertSame(0, $sweep->run());

        $this->assertSame('received', $this->scanRow($id)['state']);
        $this->assertTrue($this->log->hasErrorThatContains('could not be ended'));
    }

    #[Test]
    public function aVerdictOutsideTheThreeTheScanPostsIsRefusedByTheLeases(): void
    {
        $id = $this->fileScannableDocument('no such verdict');
        $leases = $this->service(DocumentScanLeases::class);
        $this->assertInstanceOf(ScanClaim::class, $leases->claim($id));

        $this->expectException(InvalidArgumentException::class);

        $leases->settle($id, 1, DocumentState::Erased);
    }

    #[Test]
    public function aSessionWhoseHeartbeatFaultsStopsTheWaitAndKeepsTheFaultForTheCaller(): void
    {
        $id = $this->fileScannableDocument('waiting');
        $claim = $this->service(DocumentScanLeases::class)->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);
        $session = new ScanSession($this->faulting('heartbeat'), $id, $claim->epoch, 0);

        $this->assertFalse($session->keepAlive());

        $this->assertInstanceOf(RuntimeException::class, $session->failure());
        $this->assertFalse($session->lost(), 'a fault is not a lost claim');
    }

    #[Test]
    public function aSessionWhoseMarkFaultsRefusesTheWriteAndKeepsTheFault(): void
    {
        $id = $this->fileScannableDocument('streaming');
        $claim = $this->service(DocumentScanLeases::class)->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);
        $session = new ScanSession($this->faulting('markStreaming'), $id, $claim->epoch);
        $sink = $this->acceptingSink();

        $this->assertFalse($session->accept($sink, 'a'));

        $this->assertInstanceOf(RuntimeException::class, $session->failure());
        $this->assertFalse($session->streamingStarted());
        \fclose($sink);
    }

    #[Test]
    public function aSessionWhoseClaimWasSupersededIsLostAtTheFirstWriteAndStaysLost(): void
    {
        $id = $this->fileScannableDocument('superseded');
        $claim = $this->service(DocumentScanLeases::class)->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);
        $session = new ScanSession($this->service(DocumentScanLeases::class), $id, $claim->epoch + 1);
        $sink = $this->acceptingSink();

        $this->assertFalse($session->accept($sink, 'a'));
        $this->assertTrue($session->lost());
        $this->assertFalse($session->accept($sink, 'b'), 'a lost session writes nothing more');
        $this->assertFalse($session->keepAlive());
        \fclose($sink);
    }

    #[Test]
    public function aSessionWhoseHeartbeatLosesTheClaimMidStreamIsLost(): void
    {
        $id = $this->fileScannableDocument('taken over');
        $leases = $this->service(DocumentScanLeases::class);
        $claim = $leases->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);
        $session = new ScanSession($leases, $id, $claim->epoch, 0);
        $sink = $this->acceptingSink();

        $this->assertTrue($session->accept($sink, 'a'));
        $this->assertTrue($session->streamingStarted());
        $this->connection()->executeStatement(
            'UPDATE document SET lease_epoch = lease_epoch + 1 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );

        $this->assertFalse($session->accept($sink, 'b'));
        $this->assertTrue($session->lost());
        \fclose($sink);
    }

    #[Test]
    public function aSessionWhoseScannerStopsAcceptingRemembersThatItWasTheScanner(): void
    {
        $id = $this->fileScannableDocument('refused');
        $leases = $this->service(DocumentScanLeases::class);
        $claim = $leases->claim($id);
        $this->assertInstanceOf(ScanClaim::class, $claim);
        $session = new ScanSession($leases, $id, $claim->epoch);
        $sink = WriteCallbackStream::open(static fn (): bool => false);

        $this->assertFalse($session->accept($sink, 'a'));

        $this->assertTrue($session->scannerRefused());
        $this->assertTrue($session->streamingStarted(), 'the mark was set before the byte was offered');
        \fclose($sink);
    }

    #[Test]
    public function aHealthMarkedDownStaysDownWithoutAnotherProbe(): void
    {
        $health = new ScannerHealth(new ProbeOnlyScanner(true));

        $this->assertFalse($health->knownDown(), 'nothing has been asked yet');

        $health->markDown();

        $this->assertTrue($health->knownDown());
        $this->assertTrue($health->isDown(), 'the probe that would say up is never asked');
    }

    private function faulting(string $method): DocumentScanLeases
    {
        return new FaultingScanLeases($this->service(DocumentScanLeases::class), $method);
    }

    private function scanDocumentOver(DocumentScanLeases $leases): ScanDocument
    {
        return new ScanDocument(
            $leases,
            $this->service(DocumentRepository::class),
            $this->service(DocumentStorage::class),
            $this->service(StreamEnvelopeEncryptor::class),
            new FakeMalwareScanner(),
            $this->service(AppendCustody::class),
            $this->service(TransactionManager::class),
            new Logger('observability', [$this->log]),
        );
    }

    /**
     * @return resource a scanner sink that takes everything
     */
    private function acceptingSink()
    {
        return WriteCallbackStream::open(static fn (): bool => true);
    }
}
