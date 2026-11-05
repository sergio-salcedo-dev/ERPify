<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Domain\Exception;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when a stream handed to the sealing sibling cannot be read or written to the end: a failed read, a
 * failed write (a full disk), a failed flush of the sink, or a source that stays silent for three consecutive
 * reads without reporting the end of the stream. Whatever was already written to the sink is an incomplete
 * object the caller must discard. Marker-less: an infrastructure fault, never client input. No stream content
 * is carried.
 */
final class StreamTransferFailed extends DomainException
{
    public const string TYPE = 'stream-transfer-failed';

    public static function forScope(EncryptionScopeId $scope): self
    {
        return new self(
            type: self::TYPE,
            title: 'A stream could not be transferred to its end.',
            context: ['encryptionScopeId' => $scope->toString()],
        );
    }
}
