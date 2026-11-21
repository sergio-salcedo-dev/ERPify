<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Versioning;

/**
 * The successor exclusion of a version series (`docs/adr/documents-custody-log.md` D12). A predecessor's slot is
 * held while any version below it — a child, a grandchild, … — is live: `Received`, `Scanning` or `Ready`.
 * `Quarantined`, `ScanFailed` and `Erased` hold nothing, so a contiguous run of `Erased` versions stops holding the
 * slot the moment its last live descendant is erased or quarantined, and nothing has to be written for that to happen.
 *
 * **Only the two transitions that TAKE the slot lock the series**, plus the one-off close a series erasure makes before
 * it starts: filing a new version and reopening a failed one. A version becomes live only under that lock; outside it
 * versions only stop being live, so a concurrent free can cost a take a retryable false conflict and never let two
 * successors through. That is why the erasure, the scan's compare-and-sets, the cap sweep and the replayed erasure take
 * no series lock at all.
 *
 * Every method assumes the caller owns the transaction; none opens one.
 */
interface DocumentVersionSlots
{
    /**
     * Takes the series row under `SELECT … FOR UPDATE` until the transaction ends: the mutex of every slot of the
     * series. Taken after the intent and the new scope's keystore row and before any document row.
     *
     * @return bool whether the series is still open; a closed one ({@see close()}) admits no live version again
     */
    public function lockSeries(string $seriesId): bool;

    /**
     * Whether a series erasure has closed the series, read without a lock: advisory only, for refusing a declare before
     * a byte is sent. {@see lockSeries()} is what decides.
     */
    public function isClosed(string $seriesId): bool;

    /**
     * Closes the series for good, under the same row lock a take holds, so once the caller's transaction commits no
     * take can make a version of it live: the one write a series erasure makes to the series row, before it lists what
     * to erase. A take holding the row finishes first, and its version is then listed. Idempotent.
     */
    public function close(string $seriesId): void;

    /**
     * Whether a live version exists anywhere below the document, read without a lock by one recursive query. Under the
     * series lock it is authoritative for "may a new live child appear here": nothing else can make a version live.
     */
    public function isSlotHeld(string $predecessorId): bool;
}
