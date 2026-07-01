<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Application;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;

/**
 * Stores one wrapped data-encryption key per {@see EncryptionScopeId}. Destroying a key is what makes
 * crypto-shredding possible: the row survives (for reconciliation) but its key is gone, so the ciphertext
 * it protected is permanently unreadable.
 */
interface Keystore
{
    public function wrappedDekFor(EncryptionScopeId $scope): ?WrappedDek;

    public function store(EncryptionScopeId $scope, WrappedDek $dek): void;

    /**
     * @return bool true when a live key was destroyed, false when there was none or it was already gone
     *              (idempotent)
     */
    public function destroy(EncryptionScopeId $scope): bool;
}
