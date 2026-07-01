<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Domain\Exception;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when decryption is attempted for a scope whose data-encryption key is absent or has been
 * destroyed (crypto-shredding). This is the expected, correct outcome after a subject erasure — the
 * ciphertext is permanently unreadable. Callers treat it as "not available", never as plaintext.
 */
final class DekDestroyed extends DomainException
{
    public const string TYPE = 'dek-destroyed';

    public static function forScope(EncryptionScopeId $scope): self
    {
        return new self(
            type: self::TYPE,
            title: 'The data-encryption key for this scope is unavailable.',
            context: ['encryptionScopeId' => $scope->toString()],
        );
    }
}
