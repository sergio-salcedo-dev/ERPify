<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Override;
use SodiumException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Envelope encryption with libsodium AEAD (`crypto_aead_xchacha20poly1305_ietf`). Each scope owns a
 * data-encryption key held by the {@see ScopeKeyring}. The scope id is bound as additional-authenticated-data
 * together with a caller-supplied context, so a ciphertext can be neither replayed under a different scope
 * nor relocated to another slot in the caller's model — both fail authentication. Destroying a scope's DEK
 * renders every value it protected permanently unreadable (crypto-shredding), which is the only way this
 * class "forgets".
 */
#[AsAlias(EnvelopeEncryptor::class)]
final readonly class SodiumEnvelopeEncryptor implements EnvelopeEncryptor
{
    public function __construct(
        private ScopeKeyring $keyring,
    ) {
    }

    #[Override]
    public function encrypt(EncryptionScopeId $scope, string $plaintext, string $aad): string
    {
        $dek = $this->keyring->keyForSealing($scope);
        $nonce = \random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $this->keyring->aad($scope, $aad),
            $nonce,
            $dek,
        );
        \sodium_memzero($dek);

        return \sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    #[Override]
    public function decrypt(EncryptionScopeId $scope, string $ciphertext, string $aad): string
    {
        $dek = $this->keyring->keyForOpening($scope);

        try {
            $raw = \sodium_base642bin($ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException) {
            \sodium_memzero($dek);

            throw DecryptionFailed::forScope($scope);
        }

        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        // A truncated or corrupt stored value can decode to fewer bytes than a nonce; the AEAD open *throws*
        // on a wrong-length nonce (it only returns false for an authentication failure), so catch it and
        // surface the same integrity signal instead of an unmapped 500 with the DEK left in memory.
        try {
            $plaintext = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                \substr($raw, $nonceLength),
                $this->keyring->aad($scope, $aad),
                \substr($raw, 0, $nonceLength),
                $dek,
            );
        } catch (SodiumException) {
            \sodium_memzero($dek);

            throw DecryptionFailed::forScope($scope);
        }

        \sodium_memzero($dek);

        if (false === $plaintext) {
            throw DecryptionFailed::forScope($scope);
        }

        return $plaintext;
    }

    #[Override]
    public function destroyScope(EncryptionScopeId $scope, DestroyReason $reason): bool
    {
        return $this->keyring->destroy($scope, $reason);
    }
}
