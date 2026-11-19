<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The origin's guard refused a derivative: the resource is absent, not yet readable, or already erased. Nothing was
 * kept — the row was never inserted and the stored bytes are deleted before this propagates. It carries no context,
 * so no problem body names the origin.
 *
 * Its type stays out of {@see ImageProblemType}, which is the image WRITE contract a client mirrors: no route raises
 * this, only another context's use case.
 */
final class DerivativeOriginNotLive extends DomainException implements Conflict
{
    public const string TYPE = 'image-origin-not-live';

    public static function create(): self
    {
        return new self(
            type: self::TYPE,
            title: 'The resource this image derives from cannot receive a derivative.',
        );
    }
}
