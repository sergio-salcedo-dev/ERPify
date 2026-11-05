<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Domain\Exception;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when a keystore row lock is requested outside a transaction, where the lock would be released the
 * instant the statement ends and would guard nothing. Marker-less: a wiring fault, never client input.
 */
final class KeystoreLockRequiresTransaction extends DomainException
{
    public const string TYPE = 'keystore-lock-requires-transaction';

    public static function forScope(EncryptionScopeId $scope): self
    {
        return new self(
            type: self::TYPE,
            title: 'A keystore row lock must be taken inside a transaction.',
            context: ['encryptionScopeId' => $scope->toString()],
        );
    }
}
