<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when an {@see \Erpify\Shared\Crypto\Domain\EncryptionScopeId} cannot be built because a part is
 * empty or contains the `:` separator that joins them. Marker-less: the scope is derived from trusted
 * internal data, so a malformed scope is a wiring fault, not client input. The offending value is never
 * carried.
 */
final class InvalidEncryptionScopeId extends DomainException
{
    public const string TYPE = 'invalid-encryption-scope-id';

    public static function malformed(): self
    {
        return new self(
            type: self::TYPE,
            title: 'Encryption scope id parts must be non-empty and free of the ":" separator.',
        );
    }
}
