<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Scanning;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\Upload\DocumentScanWakeUp;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Exception\DocumentNotRescannable;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\RescanReason;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Psr\Log\LoggerInterface;

/**
 * The operator's decision to scan a `ScanFailed` document again: both counters reset, the epoch advanced (so a worker
 * of the failed claim can never post over the new one), due now. Only `ScanFailed` is reopened: `Ready` is never
 * rescanned and `Quarantined` is a verdict, not a failure.
 *
 * The operator is the custody row's actor, not a separate column, and the reason — a closed vocabulary validated at
 * the edge — is the row's `reason`; it is also logged on the observability channel, which carries no free text. The
 * scan sweep is woken in the same transaction, as the promotion does, so the reopen is not left waiting for the next
 * tick.
 *
 * The decision is made on the compare-and-set, never on a prior read: a document that is not `ScanFailed` when the
 * statement runs is refused whatever it was a moment ago.
 */
final readonly class RescanDocument
{
    public function __construct(
        private DocumentScanLeases $leases,
        private DocumentRepository $documents,
        private AppendCustody $custody,
        private DocumentScanWakeUp $wakeUp,
        private TransactionManager $transactionManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws DocumentNotFound       when no document has this id
     * @throws DocumentNotRescannable when the document is not in `ScanFailed`
     */
    public function rescan(string $documentId, RescanReason $reason, string $operatorId): void
    {
        Uuid::ensure($documentId);
        Uuid::ensure($operatorId);

        $documentId = \strtolower($documentId);

        $reopened = $this->transactionManager->transactional(function () use ($documentId, $operatorId, $reason): bool {
            if (!$this->leases->reopen($documentId)) {
                return false;
            }

            $this->custody->append($documentId, CustodyEvent::RescanRequested, $operatorId, $reason->value);
            $this->wakeUp->wake($documentId);

            return true;
        });

        if (!$reopened) {
            throw $this->documents->findById($documentId) instanceof Document
                ? DocumentNotRescannable::create()
                : DocumentNotFound::create();
        }

        $this->logger->info('A document scan was reopened by an operator.', [
            'documentId' => $documentId,
            'reason' => $reason->value,
        ]);
    }
}
