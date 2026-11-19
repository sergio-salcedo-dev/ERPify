<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Erasure;

/**
 * The statements the erasure issues against `document`: the `Erased` compare-and-set and the progress marker of the
 * object-deletion sweep. Kept apart from {@see \Erpify\Documents\Document\Domain\Repository\DocumentRepository}, whose
 * one `UPDATE document` belongs to the person purge's lock-order gate, and from the scan's compare-and-sets, which
 * never see an `Erased` row.
 *
 * Every method assumes the caller owns the transaction where one is needed; none opens one.
 */
interface DocumentErasures
{
    /**
     * Bounds how long this transaction may wait for a lock and run one statement, so an erasure cannot hold the
     * stack behind a stuck peer. `SET LOCAL`: it ends with the transaction.
     */
    public function boundTransactionTime(): void;

    /**
     * The `Erased` transition: one statement that moves a non-terminal row to `Erased` and nulls the sealed filename
     * in the same breath, and says whether it did. It touches no lease or scan column and sets no slot, so a scan
     * worker's later verdict, which names the state it expects, matches nothing. `true` is the ONLY verdict on
     * whether this call erased the row; the keystore's answer decides nothing.
     */
    public function markErased(string $documentId): bool;

    /**
     * `Erased` rows whose sealed object has not been confirmed deleted, ascending by id after `$afterId` when given.
     * A candidate list: the sweep deletes each and sets the marker.
     *
     * @return list<ErasedObject>
     */
    public function awaitingObjectDeletion(int $limit, ?string $afterId = null): array;

    /**
     * Records that neither the staged nor the final object of the row exists any more. Only an `Erased` row whose
     * marker is still empty takes it, so a repeat reports false and changes nothing.
     */
    public function markObjectDeleted(string $documentId): bool;

    /**
     * Empties the marker of an `Erased` row so the deletion sweep takes its object up again: a restore replays an
     * erasure over objects that came back from a backup the marker knows nothing about.
     */
    public function reopenObjectDeletion(string $documentId): bool;

    /**
     * Ids of `Erased` rows whose object is still not confirmed deleted although the erasure was recorded at least
     * `$olderThanSeconds` ago: the sealed bytes of a document the system said it erased.
     *
     * @return list<string>
     */
    public function objectsOverdue(int $olderThanSeconds, int $limit): array;

    /**
     * Ids of `Erased` rows whose derivatives have not been confirmed released, ascending after `$afterId` when given.
     * A candidate list: the release sweep asks the images module about each and sets the marker.
     *
     * @return list<string>
     */
    public function awaitingDerivativeRelease(int $limit, ?string $afterId = null): array;

    /**
     * Records that no image derives from the row any more. Only an `Erased` row whose marker is still empty takes it,
     * so a repeat reports false and changes nothing.
     */
    public function markDerivativesReleased(string $documentId): bool;

    /**
     * Ids of `Erased` rows whose derivatives are still not confirmed released although the erasure was recorded at
     * least `$olderThanSeconds` ago: legible renderings of a document the system said it erased.
     *
     * @return list<string>
     */
    public function derivativesOverdue(int $olderThanSeconds, int $limit): array;
}
