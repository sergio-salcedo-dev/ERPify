<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;

/**
 * A declared size of zero or above {@see \Erpify\Documents\Document\Domain\UploadLimits::MAX_ORIGINAL_BYTES}.
 * Refused at the declare step, before an intent or a keystore row exists, so it leaves no tombstone.
 */
final class DeclaredSizeOutOfRange extends DomainException implements InvariantViolation
{
    public const string TYPE = 'declared-size-out-of-range';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'The declared size is outside the accepted range.');
    }
}
