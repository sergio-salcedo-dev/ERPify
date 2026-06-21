<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Domain\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;

/**
 * Thrown when a filter value is not acceptable. Two sources:
 *  - the domain {@see \Erpify\Shared\Search\Domain\Filter} constructor rejects a blank value
 *    (empty after a Unicode-aware trim), an invariant that holds for every adapter — the
 *    field's normalizer would otherwise fold it to blank and the applier would 500 on it;
 *  - the filter applier rejects a value that does not match the format the field's mapping
 *    requires (a malformed uuid against a UUID column or a malformed datetime against a
 *    timestamp column, either of which Postgres would otherwise reject with a 22xxx → 500).
 *
 * Implements the {@see InvalidSearchCriteria} marker so the Problem Details pipeline maps it
 * to HTTP 400 with no extra wiring.
 *
 * Only the public field name and the 0-based position of the offending value travel in
 * `context` — never the value itself, keeping the error payload free of arbitrary client
 * input while staying debuggable for long `in` lists.
 */
final class InvalidSearchValue extends DomainException implements InvalidSearchCriteria
{
    public const string TYPE = 'invalid-search-value';

    public static function mustNotBeBlank(string $field, int $position): self
    {
        return new self(
            type: self::TYPE,
            title: 'Search value must not be blank.',
            context: ['field' => $field, 'position' => $position],
        );
    }

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
