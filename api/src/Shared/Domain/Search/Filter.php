<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search;

/**
 * Single search filter over the PUBLIC field name of a resource (the
 * serialized property, e.g. "name" — never a DQL path; translating to
 * paths is Infrastructure's monopoly via the per-repository field map).
 *
 * The named constructors fix the value shape per operator: scalar for
 * eq/contains, list for in.
 */
final readonly class Filter
{
    /**
     * @param string|list<string> $value
     */
    private function __construct(
        public string $field,
        public FilterOperator $operator,
        public array|string $value,
    ) {
    }

    public static function eq(string $field, string $value): self
    {
        return new self($field, FilterOperator::Eq, $value);
    }

    /**
     * @param list<string> $values
     */
    public static function in(string $field, array $values): self
    {
        return new self($field, FilterOperator::In, $values);
    }

    public static function contains(string $field, string $value): self
    {
        return new self($field, FilterOperator::Contains, $value);
    }
}
