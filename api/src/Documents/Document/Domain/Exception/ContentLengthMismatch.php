<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;

/**
 * The PUT's `Content-Length` is not the size the declare step recorded. Refused before the claim, so the
 * intent stays `Declared` and a corrected request can still take it.
 */
final class ContentLengthMismatch extends DomainException implements InvariantViolation
{
    public const string TYPE = 'content-length-mismatch';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'The Content-Length does not match the declared size.');
    }
}
