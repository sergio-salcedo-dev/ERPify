<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\Scanning\SweepCappedDocuments;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\ScanClaim;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ExpireScanLeasesHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ExpireScanLeasesMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ScanDueDocumentsHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ScanDueDocumentsMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\SweepCappedDocumentsHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\SweepCappedDocumentsMessage;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Double\Scanner\FakeMalwareScanner;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The three scan ticks of the `documents_maintenance` schedule, as the worker delivers them: one tick runs its pass
 * against real rows, and the duplicate tick a second scheduler replica could deliver finds nothing left to do.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") three handlers, their messages and the rows they act on
 */
#[CoversClass(ScanDueDocumentsHandler::class)]
#[CoversClass(ExpireScanLeasesHandler::class)]
#[CoversClass(SweepCappedDocumentsHandler::class)]
#[CoversClass(ScanDueDocumentsMessage::class)]
#[CoversClass(ExpireScanLeasesMessage::class)]
#[CoversClass(SweepCappedDocumentsMessage::class)]
final class ScanTickHandlersFunctionalTest extends KernelTestCase
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
    public function theScanTickScansWhatIsDueOnceAndADuplicateTickFindsNothing(): void
    {
        $id = $this->fileScannableDocument('due');
        $handler = new ScanDueDocumentsHandler($this->sweep());

        $handler(new ScanDueDocumentsMessage());
        $handler(new ScanDueDocumentsMessage());

        $row = $this->scanRow($id);
        $this->assertSame(['ready', 1], [$row['state'], $row['epoch']], 'one claim, however many ticks');
        $this->assertSame(['received', 'scan_claimed', 'scan_ready'], $this->custodyEvents($id));
        $this->assertSame(1, FakeMalwareScanner::scans());
    }

    #[Test]
    public function theLeaseExpiryTickTakesBackALapsedClaimOnceAndADuplicateTickChangesNothing(): void
    {
        $id = $this->fileScannableDocument('worker died');
        $this->assertInstanceOf(ScanClaim::class, $this->service(DocumentScanLeases::class)->claim($id));
        $this->lapseTheLease($id);
        $handler = new ExpireScanLeasesHandler($this->expiry(new ProbeOnlyScanner(true)));

        $handler(new ExpireScanLeasesMessage());
        $afterOne = $this->scanRow($id);
        $handler(new ExpireScanLeasesMessage());
        $afterTwo = $this->scanRow($id);

        $this->assertSame('received', $afterOne['state']);
        $this->assertSame(1, $afterOne['outage'], 'streaming had not begun, so the return is an outage');
        $this->assertSame($afterOne['outage'], $afterTwo['outage']);
        $this->assertSame($afterOne['epoch'], $afterTwo['epoch']);
    }

    #[Test]
    public function theCapSweepTickEndsAStrandedRowOnceAndADuplicateTickChangesNothing(): void
    {
        $id = $this->fileScannableDocument('stranded');
        $this->connection()->executeStatement(
            'UPDATE document SET scan_attempts = 3 WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $handler = new SweepCappedDocumentsHandler(new SweepCappedDocuments(
            $this->service(DocumentScanLeases::class),
            $this->service(AppendCustody::class),
            $this->service(TransactionManager::class),
            new Logger('observability', [$this->log]),
        ));

        $handler(new SweepCappedDocumentsMessage());
        $handler(new SweepCappedDocumentsMessage());

        $this->assertSame('scan_failed', $this->scanRow($id)['state']);
        $this->assertSame(['received', 'scan_failed'], $this->custodyEvents($id), 'one verdict row, not two');
    }
}
