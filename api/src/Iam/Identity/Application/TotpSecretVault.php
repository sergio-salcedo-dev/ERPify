<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorIntegrityFailure;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use SensitiveParameter;

/**
 * The one place a second-factor secret is sealed and opened, so the scope and the associated data have one
 * spelling for the enrolment that seals, the confirmation (and later the login step) that opens, and the
 * erasure that destroys the key.
 *
 * **The scope is the factor's own random id** ({@see TotpFactor::scopeId()}) under the `TotpFactor` type. It is
 * deliberately not derived from the person's id: a destroyed key leaves a `dek_keystore` tombstone for ever, and
 * the erasure evidence that answers it carries the scope in `audit_log.metadata`, the one column no erasure
 * rewrites — a scope named by the person would keep their id in both.
 *
 * **The associated data is bound to the identity**: the column it belongs to plus the identity's canonical id.
 * A sealed secret copied onto another identity's row fails authentication there, so a write to that one column
 * cannot hand an attacker's authenticator to somebody else's account.
 *
 * **Opening is a read of sealed data, and its outcomes are translated here** (`make php.lint.crypto-read-boundary`).
 * On this path `dek-destroyed` is not the expected post-erasure outcome it is on the Documents read: the erasure
 * deletes the factor row and destroys its key in one transaction, so a live row whose key is gone is corruption,
 * like a ciphertext that no longer authenticates. Both become the marker-less
 * {@see SecondFactorIntegrityFailure} — a 500 the error tracker sees, carrying the cause as `previous` and nothing
 * about the scope or the identity in the response.
 */
final readonly class TotpSecretVault
{
    public const string SCOPE_TYPE = 'TotpFactor';

    private const string ASSOCIATED_DATA_PREFIX = 'identity_totp_factor.sealed_secret:';

    public function __construct(
        private EnvelopeEncryptor $encryptor,
    ) {
    }

    public static function scopeOf(string $scopeId): EncryptionScopeId
    {
        return EncryptionScopeId::of(self::SCOPE_TYPE, $scopeId);
    }

    /**
     * Seals `$secret` under the factor scope `$scopeId` for `$userId`, minting the scope's key on first use.
     */
    public function seal(string $scopeId, string $userId, #[SensitiveParameter] string $secret): string
    {
        return $this->encryptor->encrypt(self::scopeOf($scopeId), $secret, $this->associatedData($userId));
    }

    /**
     * The factor's secret in clear, for the caller to verify a code with and forget.
     *
     * @throws SecondFactorIntegrityFailure when the key is gone or the ciphertext does not authenticate
     */
    public function open(TotpFactor $factor): string
    {
        try {
            return $this->encryptor->decrypt(
                self::scopeOf($factor->scopeId()),
                $factor->sealedSecret(),
                $this->associatedData($factor->userId()),
            );
        } catch (DecryptionFailed|DekDestroyed $failure) {
            throw SecondFactorIntegrityFailure::create($failure);
        }
    }

    private function associatedData(string $userId): string
    {
        return self::ASSOCIATED_DATA_PREFIX . \strtolower($userId);
    }
}
