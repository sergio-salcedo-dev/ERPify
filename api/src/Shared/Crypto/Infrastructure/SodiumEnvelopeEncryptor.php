<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Domain\Exception\InvalidKek;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Envelope encryption with libsodium AEAD (`crypto_aead_xchacha20poly1305_ietf`). Each scope owns a
 * data-encryption key minted with a CSPRNG on first use, wrapped by the key-encryption key (KEK) held in
 * the environment (never beside the DEKs) and stored in the {@see Keystore}. The scope id is bound as
 * additional authenticated data, so a ciphertext cannot be replayed under a different scope. Destroying a
 * scope's DEK renders every value it protected permanently unreadable (crypto-shredding), which is the
 * only way this class "forgets".
 */
#[AsAlias(EnvelopeEncryptor::class)]
final readonly class SodiumEnvelopeEncryptor implements EnvelopeEncryptor
{
    private const int KEK_VERSION = 1;

    public function __construct(
        private Keystore $keystore,
        #[Autowire('%env(AUDIT_KEK)%')]
        private string $kek,
    ) {
        if (SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($this->kek)) {
            throw InvalidKek::wrongLength(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        }
    }

    #[Override]
    public function encrypt(EncryptionScopeId $scope, string $plaintext): string
    {
        $dek = $this->dekFor($scope);
        $nonce = \random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $scope->toString(), $nonce, $dek);
        \sodium_memzero($dek);

        return \sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    #[Override]
    public function decrypt(EncryptionScopeId $scope, string $ciphertext): string
    {
        $wrapped = $this->keystore->wrappedDekFor($scope);

        if (!$wrapped instanceof WrappedDek) {
            throw DekDestroyed::forScope($scope);
        }

        $dek = $this->unwrap($scope, $wrapped);
        $raw = \sodium_base642bin($ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $plaintext = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            \substr($raw, $nonceLength),
            $scope->toString(),
            \substr($raw, 0, $nonceLength),
            $dek,
        );
        \sodium_memzero($dek);

        if (false === $plaintext) {
            throw DecryptionFailed::forScope($scope);
        }

        return $plaintext;
    }

    #[Override]
    public function destroyScope(EncryptionScopeId $scope): bool
    {
        return $this->keystore->destroy($scope);
    }

    private function dekFor(EncryptionScopeId $scope): string
    {
        return $this->unwrap($scope, $this->keystore->wrappedDekFor($scope) ?? $this->mint($scope));
    }

    private function mint(EncryptionScopeId $scope): WrappedDek
    {
        $dek = \sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        $wrapped = $this->wrap($scope, $dek);
        \sodium_memzero($dek);
        $this->keystore->store($scope, $wrapped);

        // Re-read so every value for this scope is sealed under the authoritative stored key, even if a
        // concurrent mint won the ON CONFLICT race.
        return $this->keystore->wrappedDekFor($scope) ?? $wrapped;
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
        $dek = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            \substr($wrapped->bytes, $nonceLength),
            $scope->toString(),
            \substr($wrapped->bytes, 0, $nonceLength),
            $this->kek,
        );

        if (false === $dek) {
            throw DecryptionFailed::forScope($scope);
        }

        return $dek;
    }
}
