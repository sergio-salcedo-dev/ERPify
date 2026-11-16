<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\LengthRequired;

/**
 * An image body arrived without a usable `Content-Length`, or with one that is not the number of bytes read. A body
 * the transport cut short reads as a complete shorter one, and storing it would store a truncated image, so the
 * declared length is what tells the two apart.
 */
final class ImageLengthRequired extends DomainException implements LengthRequired
{
    public const string TYPE = ImageProblemType::LENGTH_REQUIRED;

    public static function missing(): self
    {
        return new self(type: self::TYPE, title: 'The image upload must declare its Content-Length.');
    }

    public static function notEqualToBytesRead(): self
    {
        return new self(type: self::TYPE, title: 'The image body is not as long as its Content-Length declares.');
    }
}
