<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\ReceiveDocument;
use Erpify\Documents\Document\Application\Upload\UploadEvidence;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * Documents that are really sealed, stored and filed: the plaintext is sealed under a live key into the final
 * namespace, the row is received and promoted and made due, so the scan has real bytes to unseal. Rows are read back
 * through DBAL for the reason {@see PlantsDocumentRows} gives.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait PlantsScannableDocuments
{
    use PlantsDocumentRows;
    use ResolvesContainerServices;

    /** @var list<string> */
    private array $scannableScopes = [];

    private function discardScannableObjects(): void
    {
        foreach ($this->scannableScopes as $scope) {
            $this->service(DocumentStorage::class)->delete(StorageArea::Staging, $scope);
            $this->service(DocumentStorage::class)->delete(StorageArea::Final, $scope);
        }

        $this->scannableScopes = [];
    }

    /**
     * Files a sealed, promoted, due document and returns its id.
     */
    private function fileScannableDocument(string $plaintext): string
    {
        $id = $this->fileUnpromotedDocument($plaintext);
        $document = $this->service(DocumentRepository::class)->findById($id);
        $this->assertNotNull($document);
        $this->service(DocumentStorage::class)->promote($document->scopeId());
        $this->assertTrue($this->service(DocumentRepository::class)->markPromoted($id));

        return $id;
    }

    /**
     * Files a sealed, due document whose object is still in staging and whose `promoted_at` is NULL: the state a
     * committed upload is in until the rename.
     */
    private function fileUnpromotedDocument(string $plaintext): string
    {
        $scope = RandomUuid::v4()->toRfc4122();
        $this->scannableScopes[] = $scope;
        $storage = $this->service(DocumentStorage::class);
        $sealer = $this->service(StreamEnvelopeEncryptor::class);

        $source = \fopen('php://memory', 'w+');
        $this->assertIsResource($source);
        \fwrite($source, $plaintext);
        \rewind($source);

        $storage->stageWith($scope, static function ($sink) use ($sealer, $scope, $source): void {
            $sealer->seal(
                EncryptionScopeId::forDocument($scope),
                $source,
                $sink,
                UploadEvidence::ORIGINAL_ASSOCIATED_DATA,
            );
        });
        \fclose($source);

        $document = $this->service(ReceiveDocument::class)->receive(
            $scope,
            Uuid::generate(),
            \hash('sha256', $plaintext),
            \strlen($plaintext),
        );

        $this->connection()->executeStatement(
            "UPDATE document SET next_scan_at = NOW() - INTERVAL '1 minute' WHERE id = CAST(:id AS UUID)",
            ['id' => $document->documentId()],
        );

        return $document->documentId();
    }

    /**
     * `delay` is the seconds from now to the next due scan.
     *
     * @return array{state: string, attempts: int, outage: int, epoch: int, streaming: bool, claimed: bool,
     *     recorded: bool, delay: float}
     */
    private function scanRow(string $id): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT state, scan_attempts, scan_outage_returns, lease_epoch, '
            . '(scan_streaming_started_at IS NOT NULL) AS streaming, (claimed_at IS NOT NULL) AS claimed, '
            . 'first_claim_recorded, EXTRACT(EPOCH FROM (next_scan_at - NOW())) AS delay '
            . 'FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $this->assertIsArray($row);

        return [
            'state' => $this->stringOf($row['state'] ?? null),
            'attempts' => $this->intOf($row['scan_attempts'] ?? null),
            'outage' => $this->intOf($row['scan_outage_returns'] ?? null),
            'epoch' => $this->intOf($row['lease_epoch'] ?? null),
            'streaming' => true === ($row['streaming'] ?? null),
            'claimed' => true === ($row['claimed'] ?? null),
            'recorded' => true === ($row['first_claim_recorded'] ?? null),
            'delay' => (float) $this->stringOf(\is_scalar($row['delay'] ?? null) ? (string) $row['delay'] : null),
        ];
    }

    /**
     * @return list<string> the custody events of the document, in order
     */
    private function custodyEvents(string $documentId): array
    {
        /** @var list<string> $events */
        $events = $this->connection()->fetchFirstColumn(
            'SELECT event FROM document_custody WHERE document_id = CAST(:id AS UUID) ORDER BY seq',
            ['id' => $documentId],
        );

        return $events;
    }

    private function makeDue(string $documentId): void
    {
        $this->connection()->executeStatement(
            "UPDATE document SET next_scan_at = NOW() - INTERVAL '1 minute' WHERE id = CAST(:id AS UUID)",
            ['id' => $documentId],
        );
    }
}
