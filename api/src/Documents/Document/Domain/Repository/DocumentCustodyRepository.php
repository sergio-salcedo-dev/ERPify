<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Repository;

use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\Exception\CustodySequenceCollision;
use Erpify\Documents\Document\Domain\Projection\CustodyTrailRow;

/**
 * Port for the append-only custody log of a document ({@see DocumentCustody}).
 *
 * There is no `save`, `remove` or `update` here and that is the contract: a row is inserted once, and the one
 * statement that ever rewrites one is {@see anonymiseActor()}.
 */
interface DocumentCustodyRepository
{
    /**
     * The sequence number the document's next custody row takes: the highest committed one plus one, or 1.
     *
     * Takes the locked {@see Document} rather than an id, so a caller cannot compute it for a row it has not
     * locked: the number is only meaningful while nobody else can append to the same document.
     */
    public function nextSequenceFor(Document $locked): int;

    /**
     * @throws CustodySequenceCollision when `UNIQUE (document_id, seq)` fires, so the caller re-runs its whole
     *                                  transaction
     */
    public function append(DocumentCustody $custody): void;

    /**
     * Up to `$limit` of the document's rows after `$afterSeq`, ascending by `seq`: a read-only query over
     * `UNIQUE (document_id, seq)`, scoped to the one document, so a cursor can never cross into another. Takes no
     * lock and joins no transaction.
     *
     * @param int<0, max> $afterSeq the last `seq` the caller already holds; 0 reads from the start
     * @param int<1, max> $limit
     *
     * @return list<CustodyTrailRow>
     */
    public function trailAfter(string $documentId, int $afterSeq, int $limit): array;

    /**
     * Rewrites the actor of every custody row naming the subject to
     * {@see \Erpify\Documents\Document\Domain\ErasedPersonMarker::UUID}, in every state of the document it
     * belongs to, and returns how many rows were rewritten. The one sanctioned write to the log after insert.
     *
     * One statement over the subject's rows in ascending id, each locked first, run after the document pass
     * ({@see DocumentRepository::anonymiseUploader()}) inside the caller's transaction. Idempotent.
     */
    public function anonymiseActor(string $subjectId): int;
}
