<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Exception\ErasureLedgerUnavailable;
use Erpify\Documents\Document\Domain\Exception\ErasureOutcomeUnknown;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Repository\DocumentSeriesRepository;
use Erpify\Documents\Document\Domain\Versioning\DocumentVersionSlots;
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
 * **A series is erased version by version** ({@see eraseSeries()}): each one is this same erasure, newest first, so
 * the ledger entry precedes every key it destroys and no transaction holds more than one document. An erasure never
 * takes the series lock, because it only ever frees a version slot; the series is closed to new takes once, up front.
 *
 * This class has no replay mode and takes no parameter that skips the ledger: the restore and the sweep go through
 * {@see ReplayErasure}, which a gate holds to exactly two callers.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the ledger, its three endings, the transaction and their problems
 */
final readonly class EraseDocument
{
    /**
     * SQLSTATEs a failed erasure transaction is classified by: `55P03` lock_not_available, `57014` query_canceled.
     * Pairs rather than a map, because PHP turns the all-digit key `'57014'` into an integer.
     */
    private const array SQLSTATE_CAUSES = [['55P03', 'lock-timeout'], ['57014', 'statement-timeout']];

    public function __construct(
        private DocumentRepository $documents,
        private ErasureLedger $ledger,
        private ApplyErasure $applyErasure,
        private LoggerInterface $logger,
        private DocumentSeriesRepository $series,
        private DocumentVersionSlots $slots,
    ) {
    }

    /**
     * @throws DocumentNotFound         when no document has this id
     * @throws ErasureLedgerUnavailable when the ledger entry is certainly not durable; nothing was erased
     * @throws ErasureOutcomeUnknown    when the ledger write timed out, or the transaction failed once it was durable;
     *                                  nothing was erased by this call and the sweep may finish it
     *
     * @return bool whether THIS call moved the document to `Erased`; a repeat, or a race another erasure won, is false
     */
    public function erase(string $documentId, ErasureReason $reason, string $operatorId): bool
    {
        Uuid::ensure($documentId);
        Uuid::ensure($operatorId);

        $documentId = \strtolower($documentId);
        $document = $this->documents->findById($documentId) ?? throw DocumentNotFound::create();

        if (DocumentState::Erased === $document->state()) {
            return false;
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

        return $erased;
    }

    /**
     * Erases every version of a series that is not `Erased`, newest first, each through {@see erase()} — its own ledger
     * entry and its own transaction, so a version erased is never undone by a later failure — and reports what remains.
     * It closes the series first (`DocumentVersionSlots::closeSeries()`, outside every erasure transaction), so no
     * version can be filed or reopened in it from then on and the run terminates. It is repeatable: a failure stops
     * the run where it stands and propagates; the versions erased before it stay erased and a repeat continues from
     * there.
     *
     * @throws DocumentNotFound         when no document belongs to the series
     * @throws ErasureLedgerUnavailable when a version's ledger entry is certainly not durable
     * @throws ErasureOutcomeUnknown    when a version's ledger write timed out, or its transaction failed once durable
     */
    public function eraseSeries(string $seriesId, ErasureReason $reason, string $operatorId): SeriesErasure
    {
        Uuid::ensure($seriesId);
        Uuid::ensure($operatorId);

        $seriesId = \strtolower($seriesId);

        if (!$this->series->exists($seriesId)) {
            throw DocumentNotFound::create();
        }

        // Closed first and committed on its own, so what is listed next already includes any version a take filed
        // before it, and a take that starts afterwards is refused: the run ends instead of chasing new versions.
        $this->slots->closeSeries($seriesId);

        $erased = [];

        foreach ($this->series->unerasedNewestFirst($seriesId) as $documentId) {
            if ($this->erase($documentId, $reason, $operatorId)) {
                $erased[] = $documentId;
            }
        }

        $remaining = $this->series->unerasedNewestFirst($seriesId);

        $this->logger->info('A document series was erased by an operator.', [
            'seriesId' => $seriesId,
            'reason' => $reason->value,
            'erased' => \count($erased),
            'remaining' => \count($remaining),
        ]);

        return new SeriesErasure($seriesId, $erased, $remaining);
    }

    /**
     * A slug for why the transaction failed, read from the chain's SQLSTATEs and class names and never from a message,
     * which can quote a statement or a key.
     */
    private function causeOf(Throwable $failure): string
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            $slug = $this->slugOf($cause);

            if (null !== $slug) {
                return $slug;
            }
        }

        return 'erasure-transaction-failed';
    }

    /**
     * The slug one link of the chain answers for, or null when it says nothing: a SQLSTATE in its text wins over its
     * class, in the order of {@see self::SQLSTATE_CAUSES}.
     */
    private function slugOf(Throwable $cause): ?string
    {
        foreach (self::SQLSTATE_CAUSES as [$sqlState, $slug]) {
            if (\str_contains($cause->getMessage(), $sqlState)) {
                return $slug;
            }
        }

        return $cause instanceof TransientTransactionFailure ? 'transient-transaction-failure' : null;
    }
}
