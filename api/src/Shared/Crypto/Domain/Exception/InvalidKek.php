<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown at construction when the key-encryption key provided by the environment is not exactly the AEAD
 * key length. A boot-time misconfiguration, never client input; the key value itself is never carried.
 */
final class InvalidKek extends DomainException
{
    public const string TYPE = 'invalid-kek';

    public static function wrongLength(int $expected): self
    {
        return new self(
            type: self::TYPE,
            title: \sprintf('The key-encryption key must be exactly %d bytes.', $expected),
        );
    }
}
