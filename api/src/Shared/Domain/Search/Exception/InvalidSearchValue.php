<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;

/**
 * Thrown by the filter applier when a filter value does not match the format the field's
 * mapping requires (e.g. a malformed uuid against a UUID column or a malformed datetime
 * against a timestamp column, either of which Postgres would otherwise reject with a 22xxx
 * error turned into a 500). Implements the {@see InvalidSearchCriteria} marker so the Problem
 * Details pipeline maps it to HTTP 400 with no extra wiring.
 *
 * Only the public field name and the 0-based position of the offending value travel in
 * `context` — never the value itself, keeping the error payload free of arbitrary client
 * input while staying debuggable for long `in` lists.
 */
final class InvalidSearchValue extends DomainException implements InvalidSearchCriteria
{
    public const string TYPE = 'invalid-search-value';

    public static function notAUuid(string $field, int $position): self
    {
        return new self(
            type: self::TYPE,
            title: 'Search value must be a valid UUID.',
            context: ['field' => $field, 'position' => $position],
        );
    }

    public static function notADateTime(string $field, int $position): self
    {
        return new self(
            type: self::TYPE,
            title: 'Search value must be a valid ISO-8601 datetime.',
            context: ['field' => $field, 'position' => $position],
        );
    }
}
