<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;

/**
 * Thrown by the filter applier when a filter value does not match the format the field's
 * mapping requires (e.g. a malformed uuid against a UUID column, which Postgres would
 * otherwise reject with a 22P02 turned into a 500). Implements the
 * {@see InvalidSearchCriteria} marker so the Problem Details pipeline maps it to HTTP 400
 * with no extra wiring.
 *
 * Only the public field name travels in `context` — never the offending value, keeping the
 * error payload free of arbitrary client input.
 */
final class InvalidSearchValue extends DomainException implements InvalidSearchCriteria
{
    public const string TYPE = 'invalid-search-value';

    public static function notAUuid(string $field): self
    {
        return new self(
            type: self::TYPE,
            title: 'Search value must be a valid UUID.',
            context: ['field' => $field],
        );
    }
}
