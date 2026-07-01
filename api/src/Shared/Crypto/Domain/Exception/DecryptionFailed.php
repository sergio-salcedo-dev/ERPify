<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Domain\Exception;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when an AEAD open fails while the key is present — a tampered ciphertext or a scope mismatch
 * (the scope is bound as additional authenticated data). Distinct from {@see DekDestroyed}: the key
 * exists, but integrity verification failed, which is an integrity signal worth surfacing.
 */
final class DecryptionFailed extends DomainException
{
    public const string TYPE = 'decryption-failed';

    public static function forScope(EncryptionScopeId $scope): self
    {
        return new self(
            type: self::TYPE,
            title: 'Authenticated decryption failed.',
            context: ['encryptionScopeId' => $scope->toString()],
        );
    }
}
