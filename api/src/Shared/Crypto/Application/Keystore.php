<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Application;

use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\KeystoreLockRequiresTransaction;
use Erpify\Shared\Crypto\Domain\KeyState;

/**
 * Stores one wrapped data-encryption key per {@see EncryptionScopeId}. Destroying a key is what makes
 * crypto-shredding possible: the row survives (for reconciliation) but its key is gone, so the ciphertext
 * it protected is permanently unreadable.
 */
interface Keystore
{
    /**
     * The scope's live key, or null for both a destroyed scope and one never minted: the two are
     * indistinguishable here, which is what {@see lockState()} exists to tell apart.
     */
    public function wrappedDekFor(EncryptionScopeId $scope): ?WrappedDek;

    /**
     * The live keys of several scopes in ONE query: what a page of values sealed under different scopes needs, where
     * {@see wrappedDekFor()} per scope would cost one query each.
     *
     * Keyed by {@see EncryptionScopeId::toString()}. A scope with no entry is destroyed or was never minted, the same
     * indistinguishable answer {@see wrappedDekFor()} gives with null. A live row with no usable key material is
     * corruption and degrades ITS OWN entry to a {@see DecryptionFailed} value, where the single-scope method
     * throws: one corrupt row must not take the answer for the other scopes of the batch with it. Never throws for
     * a row, only for the query itself (an outage is the caller's 5xx).
     *
     * @param list<EncryptionScopeId> $scopes
     *
     * @return array<string, WrappedDek|DecryptionFailed>
     */
    public function wrappedDeksFor(array $scopes): array;

    public function store(EncryptionScopeId $scope, WrappedDek $dek): void;

    /**
     * Leaves a tombstone for the scope, minting one when the scope has no row yet so a writer that arrives
     * later cannot mint a live key under it. First writer wins: an existing tombstone keeps its reason and
     * its destruction time, so a later destroy under another reason cannot relabel it.
     *
     * {@see DestroyReason::AbortedUpload} is for a scope that has no record of the thing it protected, which
     * the caller establishes under the locks that keep one from appearing. The reconciler ignores that reason,
     * so a live scope labelled this way raises no alarm, and first-writer-wins makes the label permanent.
     *
     * @return bool true when this call wrote the tombstone (a live key was destroyed, or a scope with no row
     *              was closed), false when a tombstone was already there (idempotent). Never evidence that
     *              anything was erased: the caller's own record of the erasure decides that, not this flag.
     */
    public function destroy(EncryptionScopeId $scope, DestroyReason $reason): bool;

    /**
     * Locks the scope's row until the surrounding transaction ends and reports what it holds.
     *
     * `Absent` is a snapshot, not a guarantee: a scope with no row has nothing to lock, so a concurrent mint
     * can still insert one. A caller that needs absence to hold writes a tombstone with {@see destroy()}.
     *
     * @throws KeystoreLockRequiresTransaction when no transaction is active
     */
    public function lockState(EncryptionScopeId $scope): KeyState;
}
