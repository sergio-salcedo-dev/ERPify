<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Scanning;

use Erpify\Documents\Document\Domain\DocumentState;

/**
 * The scan's transitions, each ONE compare-and-set statement evaluated by the database clock. A transition carries
 * the `lease_epoch` it was granted, names the state it expects, and `Erased` matches none of them, so a verdict
 * arriving after an erasure changes nothing. A lost compare-and-set is an answer (false, `Lost`, null), never an
 * exception, and writes nothing.
 *
 * Every method assumes the caller owns the transaction that also appends the custody row the transition owes, so
 * the pair commits together; none opens one. The statement takes the row lock, and the custody append that follows
 * takes the same lock through the repository, so these add no pair to the context's lock order.
 */
interface DocumentScanLeases
{
    /**
     * Ids of promoted `Received` documents due now with both counters under their caps, oldest due first. A
     * candidate list only: the claim re-evaluates every predicate.
     *
     * @return list<string>
     */
    public function dueCandidates(int $limit): array;

    /**
     * Claims a due document: `Received` to `Scanning`, epoch plus one, a fresh lease and claim time, the streaming
     * mark cleared. Null when the row is no longer claimable. A down scanner never reaches this.
     */
    public function claim(string $documentId): ?ScanClaim;

    /**
     * Marks that the first byte is about to go to the scanner. False when the claim was superseded or its
     * deadline passed.
     */
    public function markStreaming(string $documentId, int $epoch): bool;

    /**
     * Renews the lease, never past the deadline fixed at the claim. False when the epoch was superseded, the row
     * left `Scanning` (`Erased` included) or the deadline passed; the worker then stops and posts no verdict.
     */
    public function heartbeat(string $documentId, int $epoch): bool;

    /**
     * Posts a verdict: `Ready`, `Quarantined` or `ScanFailed` (a deterministic failure). False when the claim was
     * superseded, the row left `Scanning` or the deadline passed.
     */
    public function settle(string $documentId, int $epoch, DocumentState $verdict): bool;

    /**
     * Returns a claim that ended with no verdict to `Received`, counting it where the accounting says: an attempt
     * when streaming had begun and the scanner is not down, an outage return otherwise. Reaching a cap ends the
     * document in `ScanFailed` in the same statement; otherwise the next scan is the ladder entry, jittered.
     *
     * @param bool $scannerDown       the run's single probe found the scanner down
     * @param bool $onlyIfLeaseLapsed the lease-expiry form: matches only a lease the database finds expired
     */
    public function returnToReceived(
        string $documentId,
        int $epoch,
        bool $scannerDown,
        bool $onlyIfLeaseLapsed,
    ): ReturnOutcome;

    /**
     * `Scanning` rows whose lease is expired, with the epoch each held.
     *
     * @return list<ScanLease>
     */
    public function expiredLeases(int $limit): array;

    /**
     * `Received` rows at or above a cap (a constant lowered at a deploy).
     *
     * @return list<string>
     */
    public function cappedCandidates(int $limit): array;

    /**
     * Moves a `Received` row found at or above a cap to `ScanFailed`. False when it no longer qualifies.
     */
    public function failCapped(string $documentId): bool;

    /**
     * The operator's reopen: `ScanFailed` to `Received`, both counters zero, epoch plus one, due now, the first
     * claim not yet recorded. False when the row is not in `ScanFailed`.
     */
    public function reopen(string $documentId): bool;
}
