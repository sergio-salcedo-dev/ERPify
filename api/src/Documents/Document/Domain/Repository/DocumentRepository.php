<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Repository;

use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;

/**
 * Aggregate-lifecycle port for {@see Document}.
 *
 * Every method that writes assumes the caller owns the transaction; none opens one.
 */
interface DocumentRepository
{
    /**
     * Inserts a new document with its first custody row, so both exist or neither does.
     *
     * @throws ConcurrentUniqueWrite when the actor-scoped idempotency key hash is already held by another document
     */
    public function add(Document $document, DocumentCustody $firstCustody): void;

    /**
     * Loads the document under `SELECT … FOR UPDATE`. The compare-and-set that follows never runs against a
     * stale snapshot, and a custody sequence number computed after this returns cannot be computed
     * concurrently for the same document.
     *
     * @throws DocumentNotFound when no document has this id
     */
    public function lockForUpdate(string $id): Document;

    /**
     * Rewrites the uploader of every document uploaded by the subject to
     * {@see \Erpify\Documents\Document\Domain\ErasedPersonMarker::UUID} and nulls its idempotency key hash,
     * whatever state it is in — `Erased` included, because this is reference anonymisation and not a state
     * change — and returns how many rows were rewritten.
     *
     * One statement over the subject's rows in ascending id, each locked first. It loads no `Document` into
     * the unit of work: a managed instance read before this runs would keep the subject's id in the identity
     * map, which is why the erasure path never reads through the ORM.
     *
     * Idempotent: a second pass finds no row naming the subject.
     */
    public function anonymiseUploader(string $subjectId): int;

    public function findById(string $id): ?Document;

    /**
     * The document holding the actor-scoped idempotency key hash, in whatever state, read without a lock: the
     * declare step's re-read after it inserted its intent.
     */
    public function findByIdempotencyKeyHash(string $keyHash): ?Document;

    public function existsForScope(string $scopeId): bool;

    /**
     * Sets `promoted_at` to the database clock by compare-and-set — only on a `Received` row whose
     * `promoted_at` is still NULL — and says whether this call set it. The caller that set it is the one
     * that dispatches the scan wake-up.
     */
    public function markPromoted(string $id): bool;

    /**
     * Ids of `Received` rows with `promoted_at` NULL created at least `$olderThanSeconds` ago, ascending.
     * A candidate list: the roll-forward rechecks each under its lock.
     *
     * @return list<string>
     */
    public function awaitingPromotion(int $olderThanSeconds, int $limit): array;
}
