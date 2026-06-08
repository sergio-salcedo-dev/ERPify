<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;

/**
 * Thrown when a search requests ordering by a public field with no entry in the repository's
 * `SortFieldMap` allow-list. Implements the {@see InvalidSearchCriteria} marker so the Problem
 * Details pipeline maps it to HTTP 400 with no extra wiring (no new marker — reuses the family).
 *
 * The offending field travels in `context` (never interpolated into the title) so the standard
 * reserved-keys / redaction layers apply before it reaches the wire.
 */
final class UnknownSortField extends DomainException implements InvalidSearchCriteria
{
    public const string TYPE = 'unknown-sort-field';

    public static function named(string $field): self
    {
        return new self(
            type: self::TYPE,
            title: 'Unknown sort field.',
            context: ['field' => $field],
        );
    }
}
