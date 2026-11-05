<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Domain\Exception\InvalidKek;
use SodiumException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The per-scope data-encryption keys behind both sealing siblings: mints a key with a CSPRNG on first use,
 * wraps it under the key-encryption key (KEK) held in the environment (never beside the DEKs) and keeps it in
 * the {@see Keystore}. The one place the wrapping, the tombstone refusal and the additional-authenticated-data
 * layout live, so the string and the stream sibling cannot drift apart on any of them.
 *
 * The plaintext DEK a method returns is the caller's to wipe with `sodium_memzero()`.
 */
final readonly class ScopeKeyring
{
    private const int KEK_VERSION = 1;

    /**
     * Joins the scope to the caller's context in the AEAD additional-authenticated-data. Any byte works;
     * this one just has to be the same on seal and open, which it is because both go through {@see aad()}.
     */
    private const string AAD_SEPARATOR = '|';

    public function __construct(
        private Keystore $keystore,
        #[Autowire(env: 'AUDIT_KEK')]
        private string $kek,
    ) {
        if (SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($this->kek)) {
            throw InvalidKek::wrongLength(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        }
    }

    /**
     * The scope's key for sealing, minted on first use.
     *
     * @throws DekDestroyed when the scope has been destroyed
     */
    public function keyForSealing(EncryptionScopeId $scope): string
    {
        return $this->unwrap($scope, $this->keystore->wrappedDekFor($scope) ?? $this->mint($scope));
    }

    /**
     * The scope's key for opening; never minted.
     *
     * @throws DekDestroyed when the scope's key is absent or has been destroyed
     */
    public function keyForOpening(EncryptionScopeId $scope): string
    {
        $wrapped = $this->keystore->wrappedDekFor($scope);

        if (!$wrapped instanceof WrappedDek) {
            throw DekDestroyed::forScope($scope);
        }

        return $this->unwrap($scope, $wrapped);
    }

    public function destroy(EncryptionScopeId $scope, DestroyReason $reason): bool
    {
        return $this->keystore->destroy($scope, $reason);
    }

    /**
     * The AEAD additional-authenticated-data: the scope (a ciphertext cannot be replayed under another
     * scope) followed by the caller's context (it cannot be relocated to another slot). Built identically
     * on seal and open, so a mismatch on either fails authentication.
     */
    public function aad(EncryptionScopeId $scope, string $aad): string
    {
        return $scope->toString() . self::AAD_SEPARATOR . $aad;
    }

    private function mint(EncryptionScopeId $scope): WrappedDek
    {
        $dek = \sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        $wrapped = $this->wrap($scope, $dek);
        \sodium_memzero($dek);
        $this->keystore->store($scope, $wrapped);

        // Re-read so every value for this scope is sealed under the authoritative stored key, even if a
        // concurrent mint won the ON CONFLICT race. A null here means the store was a no-op against a
        // tombstoned (destroyed) scope: crypto-shredding is irreversible, so refuse rather than seal under
        // an unpersisted key that no reader could ever unwrap.
        $stored = $this->keystore->wrappedDekFor($scope);

        if (!$stored instanceof WrappedDek) {
            throw DekDestroyed::forScope($scope);
        }

        return $stored;
    }

    private function wrap(EncryptionScopeId $scope, string $dek): WrappedDek
    {
        $nonce = \random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $sealed = \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, $scope->toString(), $nonce, $this->kek);

        return new WrappedDek($nonce . $sealed, self::KEK_VERSION);
    }

    private function unwrap(EncryptionScopeId $scope, WrappedDek $wrapped): string
    {
        if (self::KEK_VERSION !== $wrapped->kekVersion) {
            throw DecryptionFailed::forScope($scope);
        }

        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        // A corrupt wrapped-DEK can be too short for a nonce, which the AEAD open throws on (it only returns
        // false for an authentication failure) — surface it as DecryptionFailed rather than letting a raw
        // SodiumException escape the write path.
        try {
            $dek = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                \substr($wrapped->bytes, $nonceLength),
                $scope->toString(),
                \substr($wrapped->bytes, 0, $nonceLength),
                $this->kek,
            );
        } catch (SodiumException) {
            throw DecryptionFailed::forScope($scope);
        }

        if (false === $dek) {
            throw DecryptionFailed::forScope($scope);
        }

        return $dek;
    }
}
