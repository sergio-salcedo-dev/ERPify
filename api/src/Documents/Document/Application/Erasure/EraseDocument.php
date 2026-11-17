<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Exception\ErasureLedgerUnavailable;
use Erpify\Documents\Document\Domain\Exception\ErasureOutcomeUnknown;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Erpify\Shared\Uuid\Domain\Uuid;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The operator's erasure of one document, from any state but `Erased`: the document's key is destroyed, its sealed
 * filename and (through the sweep) its sealed object are gone, and its digest, size and custody stay.
 *
 * **Order is the contract.** The voter and the id check ran before this; then the row is read (404), an `Erased` row
 * answers as the first call did and writes nothing, and only then is the entry written to the ledger — off this host,
 * synchronously, with a bounded timeout and no database lock or transaction held, because a ledger that is slow must
 * never pin a row. The entry is durable before the key is destroyed, which is the whole point: a restore to an older
 * dump can then replay it. A failure before the entry is durable leaves the document untouched and the request
 * retryable; a timeout is outcome-unknown and says so, since an entry that landed is finished by the completion sweep.
 * Only then does {@see ApplyErasure} run its one transaction.
 *
 * This class has no replay mode and takes no parameter that skips the ledger: the restore and the sweep go through
 * {@see ReplayErasure}, which a gate holds to exactly two callers.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the ledger, its three endings, the transaction and their problems
 */
final readonly class EraseDocument
{
    public function __construct(
        private DocumentRepository $documents,
        private ErasureLedger $ledger,
        private ApplyErasure $applyErasure,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws DocumentNotFound         when no document has this id
     * @throws ErasureLedgerUnavailable when the ledger entry is certainly not durable; nothing was erased
     * @throws ErasureOutcomeUnknown    when the ledger write timed out, or the transaction failed once it was durable;
     *                                  nothing was erased by this call and the sweep may finish it
     */
    public function erase(string $documentId, ErasureReason $reason, string $operatorId): void
    {
        Uuid::ensure($documentId);
        Uuid::ensure($operatorId);

        $documentId = \strtolower($documentId);
        $document = $this->documents->findById($documentId) ?? throw DocumentNotFound::create();

        if (DocumentState::Erased === $document->state()) {
            return;
        }

        try {
            $this->ledger->record($document->scopeId());
        } catch (LedgerWriteTimedOut) {
            $this->logger->error('The erasure ledger write timed out; the outcome is unknown.', [
                'documentId' => $documentId,
            ]);

            throw ErasureOutcomeUnknown::create();
        } catch (LedgerWriteFailed) {
            $this->logger->error('The erasure ledger could not be written; nothing was erased.', [
                'documentId' => $documentId,
            ]);

            throw ErasureLedgerUnavailable::create();
        }

        try {
            $erased = $this->applyErasure->apply($documentId, $operatorId, $reason->value);
        } catch (Throwable $throwable) {
            // The entry is durable, so the erasure WILL be finished: by the completion sweep, with the system's reason.
            // Whatever stopped this transaction — a lock or statement timeout, a keystore or database fault — the
            // honest answer is the one for a write that timed out: this call erased nothing and it may still complete.
            $this->logger->error('The erasure transaction failed after the ledger entry was made durable.', [
                'documentId' => $documentId,
                'cause' => $this->causeOf($throwable),
            ]);

            throw ErasureOutcomeUnknown::create();
        }

        if ($erased) {
            $this->logger->info('A document was erased by an operator.', [
                'documentId' => $documentId,
                'reason' => $reason->value,
            ]);
        }
    }

    /**
     * A slug for why the transaction failed, read from the chain's SQLSTATEs and class names and never from a message,
     * which can quote a statement or a key.
     */
    private function causeOf(Throwable $failure): string
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            $message = $cause->getMessage();

            if (\str_contains($message, '55P03')) {
                return 'lock-timeout';
            }

            if (\str_contains($message, '57014')) {
                return 'statement-timeout';
            }

            if ($cause instanceof TransientTransactionFailure) {
                return 'transient-transaction-failure';
            }
        }

        return 'erasure-transaction-failed';
    }
}
