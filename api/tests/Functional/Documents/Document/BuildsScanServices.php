<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\Scanning\ExpireScanLeases;
use Erpify\Documents\Document\Application\Scanning\ScanDocument;
use Erpify\Documents\Document\Application\Scanning\ScanDueDocuments;
use Erpify\Documents\Document\Application\Scanning\ScannerHealth;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\MalwareScanner;
use Erpify\Documents\Document\Domain\ScanPolicy;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Double\Scanner\FakeMalwareScanner;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/**
 * The scan use cases built over the real container's repositories, storage and sealer with a scanner and a log handler
 * the test chooses. Requires {@see PlantsScannableDocuments} and a `$log` property holding the handler.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait BuildsScanServices
{
    private TestHandler $log;

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

    private function lapseTheLease(string $documentId): void
    {
        $this->connection()->executeStatement(
            "UPDATE document SET lease_expires_at = NOW() - INTERVAL '1 second' WHERE id = CAST(:id AS UUID)",
            ['id' => $documentId],
        );
    }
}
