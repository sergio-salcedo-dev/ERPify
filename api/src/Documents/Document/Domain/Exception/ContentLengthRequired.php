<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\LengthRequired;

/**
 * The evidence PUT arrived without a `Content-Length`. A body with no declared length that the transport cut
 * short reads as a complete shorter one (measured on the transport), so it is refused before the intent is
 * claimed.
 */
final class ContentLengthRequired extends DomainException implements LengthRequired
{
    public const string TYPE = 'length-required';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'The upload must declare its Content-Length.');
    }
}
