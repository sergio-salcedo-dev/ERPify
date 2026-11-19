<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The origin's guard refused a derivative: the resource is absent, not yet readable, or already erased. Nothing was
 * kept — the row was never inserted and the stored bytes are deleted before this propagates. It carries no context,
 * so no problem body names the origin.
 */
final class DerivativeOriginNotLive extends DomainException implements Conflict
{
    public static function create(): self
    {
        return new self(
            type: ImageProblemType::ORIGIN_NOT_LIVE,
            title: 'The resource this image derives from cannot receive a derivative.',
        );
    }
}
