<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Scanning;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\Upload\DocumentScanWakeUp;
use Erpify\Documents\Document\Application\Versioning\TakeVersionSlot;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Exception\DocumentNotRescannable;
use Erpify\Documents\Document\Domain\Exception\PredecessorNotReady;
use Erpify\Documents\Document\Domain\Exception\SeriesClosed;
use Erpify\Documents\Document\Domain\Exception\VersionSlotTaken;
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
 * statement runs is refused whatever it was a moment ago. The unlocked read before it only answers the common refusals
 * early and names the series and predecessor, which never change.
 *
 * **A successor takes its predecessor's slot back** ({@see TakeVersionSlot}, `docs/adr/documents-custody-log.md` D12):
 * the series row, then the predecessor row, then its own row through the compare-and-set, so one of two concurrent
 * reopens of failed siblings wins and a reopen whose slot a corrected sibling already holds is refused with the slot
 * conflict. Its own state needs no recheck: nothing but this path makes a `ScanFailed` version live, and anything else
 * that moved it is refused by the compare-and-set. A first version has no slot to take.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") one exception per refusal a reopen gives, and the slot take
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
        private TakeVersionSlot $versionSlot,
    ) {
    }

    /**
     * @throws DocumentNotFound       when no document has this id
     * @throws DocumentNotRescannable when the document is not in `ScanFailed`
     * @throws PredecessorNotReady    when the document is a successor whose predecessor is `Erased`
     * @throws VersionSlotTaken       when the document is a successor whose slot another live version holds
     * @throws SeriesClosed           when a series erasure has closed the document's series
     */
    public function rescan(string $documentId, RescanReason $reason, string $operatorId): void
    {
        Uuid::ensure($documentId);
        Uuid::ensure($operatorId);

        $documentId = \strtolower($documentId);
        $document = $this->documents->findById($documentId) ?? throw DocumentNotFound::create();

        if (DocumentState::ScanFailed !== $document->state()) {
            throw DocumentNotRescannable::create();
        }

        $reopened = $this->transactionManager->transactional(function () use (
            $document,
            $operatorId,
            $reason,
        ): bool {
            $documentId = $document->documentId();
            $this->takeBackItsPlace($document);

            if (!$this->leases->reopen($documentId)) {
                return false;
            }

            $this->custody->append($documentId, CustodyEvent::RescanRequested, $operatorId, $reason->value);
            $this->wakeUp->wake($documentId);

            return true;
        });

        if (!$reopened) {
            throw DocumentNotRescannable::create();
        }

        $this->logger->info('A document scan was reopened by an operator.', [
            'documentId' => $documentId,
            'reason' => $reason->value,
        ]);
    }

    /**
     * A successor takes its predecessor's slot back; a first version has no slot, but takes the series row all the
     * same, so that a series its erasure closed refuses it.
     *
     * @throws PredecessorNotReady when the document is a successor whose predecessor is `Erased`
     * @throws VersionSlotTaken    when the document is a successor whose slot another live version holds
     * @throws SeriesClosed        when a series erasure has closed the document's series
     */
    private function takeBackItsPlace(Document $document): void
    {
        $predecessorId = $document->predecessorId();

        if (null === $predecessorId) {
            $this->versionSlot->forFirstVersionReopen($document->seriesId());

            return;
        }

        $this->versionSlot->forReopen($document->seriesId(), $predecessorId);
    }
}
