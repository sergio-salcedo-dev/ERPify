<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * A series erasure has closed the series for good, so none of its versions can become live again: no new version is
 * filed under any of them and no failed one is reopened. Raised by the new-version declare (without a lock) and,
 * authoritatively, under the series lock by the new-version commit and by every reopen. Unlike a predecessor that is
 * not `Ready` yet, a closed series never opens again, so retrying is pointless.
 */
final class SeriesClosed extends DomainException implements Conflict
{
    public const string TYPE = 'document-series-closed';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'The document series is closed by its erasure.');
    }
}
