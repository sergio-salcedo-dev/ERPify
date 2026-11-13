<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application;

use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\Exception\CustodySequenceCollision;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Repository\DocumentCustodyRepository;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Appends one row to a document's custody log, and owns the transaction that makes the append correct.
 *
 * One transaction: lock the `document` row (`FOR UPDATE`), compute `MAX(seq) + 1`, insert the row, commit.
 * "No gaps" means among COMMITTED rows — a rolled-back append consumes no number, and no PostgreSQL sequence
 * is used for that reason.
 *
 * **A lost race re-runs the whole unit of work, never only the INSERT.** The lock on the document row
 * serialises appenders, and `UNIQUE (document_id, seq)` is the second defence for a path that reaches the
 * insert without it. When that constraint fires the number computed in this transaction is stale, so the
 * closure runs again from the lock — bounded, because a collision that survives {@see self::MAX_ATTEMPTS}
 * fresh attempts is not a race any more — and the caller then gets the answer for "try that again", the
 * transient failure. A deadlock needs no handling here: {@see TransactionManager} already answers it as
 * {@see TransientTransactionFailure}, and re-running it in this loop would only hide a lock-order defect.
 *
 * The idempotency constraint is a different thing and never reaches this loop: it lives on the document
 * insert, is a conflict, and retrying it would fail identically.
 */
final readonly class AppendCustody
{
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private DocumentRepository $documents,
        private DocumentCustodyRepository $custody,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws DocumentNotFound            when the document does not exist
     * @throws TransientTransactionFailure when the sequence number was lost to a concurrent appender on every attempt
     */
    public function append(
        string $documentId,
        CustodyEvent $event,
        ?string $actorId,
        ?string $reason = null,
    ): DocumentCustody {
        Uuid::ensure($documentId);

        if (null !== $actorId) {
            Uuid::ensure($actorId);
        }

        $attempts = 0;

        while (true) {
            ++$attempts;

            try {
                return $this->transactionManager->transactional(
                    fn (): DocumentCustody => $this->appendOnce($documentId, $event, $actorId, $reason),
                );
            } catch (CustodySequenceCollision $collision) {
                if ($attempts >= self::MAX_ATTEMPTS) {
                    throw new TransientTransactionFailure($collision);
                }
            }
        }
    }

    private function appendOnce(
        string $documentId,
        CustodyEvent $event,
        ?string $actorId,
        ?string $reason,
    ): DocumentCustody {
        $locked = $this->documents->lockForUpdate($documentId);

        $custody = DocumentCustody::record(
            Uuid::generate(),
            $documentId,
            $this->custody->nextSequenceFor($locked),
            $event,
            $actorId,
            $reason,
        );
        $this->custody->append($custody);

        return $custody;
    }
}
