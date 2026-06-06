<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;

/**
 * Thrown by the filter applier when a filter targets a public field with no entry in the
 * repository's `SearchFieldMap` allow-list. Implements the {@see InvalidSearchCriteria}
 * marker so the Problem Details pipeline maps it to HTTP 400 with no extra wiring.
 *
 * The offending field travels in `context` (never interpolated into the title) so the
 * standard reserved-keys / redaction layers apply before it reaches the wire.
 */
final class UnknownSearchField extends DomainException implements InvalidSearchCriteria
{
    public const string TYPE = 'unknown-search-field';

    public static function named(string $field): self
    {
        return new self(
            type: self::TYPE,
            title: 'Unknown search field.',
            context: ['field' => $field],
        );
    }
}
