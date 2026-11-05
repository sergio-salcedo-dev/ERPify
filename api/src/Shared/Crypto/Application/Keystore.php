<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Application;

use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
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

    public function store(EncryptionScopeId $scope, WrappedDek $dek): void;

    /**
     * Leaves a tombstone for the scope, minting one when the scope has no row yet so a writer that arrives
     * later cannot mint a live key under it. First writer wins: an existing tombstone keeps its reason and
     * its destruction time, so a later destroy under another reason cannot relabel it.
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
