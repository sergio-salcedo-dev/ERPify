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
     * Empties the markers of an `Erased` row so the sweeps take it up again: a restore replays an erasure over objects
     * and derivatives that came back from a backup the markers know nothing about. One statement for the sealed object
     * and the derivatives, so the replay's statement budget is unchanged. It also stamps the reopen, from which the
     * alarms then measure their bound.
     */
    public function reopenObjectDeletion(string $documentId): bool;

    /**
     * Ids of `Erased` rows whose object is still not confirmed deleted although the erasure was recorded, and any
     * restore reopened it, at least `$olderThanSeconds` ago: the sealed bytes of a document the system said it erased.
     *
     * @return list<string>
     */
    public function objectsOverdue(int $olderThanSeconds, int $limit): array;
}
