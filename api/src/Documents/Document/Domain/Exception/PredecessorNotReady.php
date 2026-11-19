<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * A version can be filed only under a `Ready` predecessor, and a failed version can be reopened only while its
 * predecessor is not `Erased`. An absent predecessor answers exactly like one in any other state: one type, one title,
 * no id, so the conflict tells a caller neither whether the document exists nor where it stands.
 */
final class PredecessorNotReady extends DomainException implements Conflict
{
    public const string TYPE = 'document-predecessor-not-ready';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'The predecessor is not a ready document.');
    }
}
