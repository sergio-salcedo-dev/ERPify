<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Domain;

use Erpify\Shared\Search\Domain\Exception\InvalidSearchValue;

/**
 * Single search filter over the PUBLIC field name of a resource (the
 * serialized property, e.g. "name" — never a DQL path; translating to
 * paths is Infrastructure's monopoly via the per-repository field map).
 *
 * The named constructors fix the value shape per operator: scalar for
 * eq/contains and the temporal range operators (gt/gte/lt/lte), list for in.
 *
 * A blank value (empty after a Unicode-aware trim) is rejected here so the
 * invariant holds for every adapter. The HTTP boundary
 * ({@see \Erpify\Shared\Search\Application\Http\FilterQuery}) also rejects it
 * earlier with a 422, but this domain guard keeps a blank value — which the
 * field normalizer would fold to a 500 downstream — out of any caller (CLI,
 * message handlers).
 */
final readonly class Filter
{
    /**
     * @param string|list<string> $value
     *
     * @throws InvalidSearchValue when the value (or any `in` item) is blank
     */
    private function __construct(
        public string $field,
        public FilterOperator $operator,
        public array|string $value,
    ) {
        $values = \is_string($value) ? [$value] : $value;

        foreach ($values as $position => $item) {
            if ('' === \mb_trim($item)) {
                throw InvalidSearchValue::mustNotBeBlank($field, $position);
            }
        }
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

    public static function gt(string $field, string $value): self
    {
        return new self($field, FilterOperator::Gt, $value);
    }

    public static function gte(string $field, string $value): self
    {
        return new self($field, FilterOperator::Gte, $value);
    }

    public static function lt(string $field, string $value): self
    {
        return new self($field, FilterOperator::Lt, $value);
    }

    public static function lte(string $field, string $value): self
    {
        return new self($field, FilterOperator::Lte, $value);
    }
}
