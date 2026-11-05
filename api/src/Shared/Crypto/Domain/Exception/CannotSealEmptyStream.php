<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Domain\Exception;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when a stream to seal yields no bytes. A source that produced nothing is indistinguishable, here,
 * from one that was cut off before its first byte, so it is refused instead of sealed as a valid empty
 * original; the refusal comes before any key is minted, so it leaves nothing in the keystore. Marker-less: a
 * caller that admits an empty original is a wiring fault, not client input.
 */
final class CannotSealEmptyStream extends DomainException
{
    public const string TYPE = 'cannot-seal-empty-stream';

    public static function forScope(EncryptionScopeId $scope): self
    {
        return new self(
            type: self::TYPE,
            title: 'An empty stream cannot be sealed.',
            context: ['encryptionScopeId' => $scope->toString()],
        );
    }
}
