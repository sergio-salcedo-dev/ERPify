<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Application;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;

/**
 * Envelope encryption keyed per {@see EncryptionScopeId}: values are sealed under a per-scope
 * data-encryption key, itself wrapped by a key-encryption key held outside the app. Destroying a scope's
 * key crypto-shreds every value it protected.
 */
interface EnvelopeEncryptor
{
    /**
     * Seals a value under the scope's key, minting and storing that key on first use. Returns a
     * self-contained, JSON-safe ciphertext token.
     */
    public function encrypt(EncryptionScopeId $scope, string $plaintext): string;

    /**
     * @throws DekDestroyed     when the scope's key is absent or has been destroyed
     * @throws DecryptionFailed when the key is present but authentication fails
     */
    public function decrypt(EncryptionScopeId $scope, string $ciphertext): string;

    /**
     * @return bool true when a live key was destroyed, false when there was none or it was already gone
     *              (idempotent)
     */
    public function destroyScope(EncryptionScopeId $scope): bool;
}
