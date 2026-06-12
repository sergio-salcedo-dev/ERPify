<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset;

use Erpify\Shared\Domain\Search\SortDirection;
use InvalidArgumentException;

/**
 * Pre-compiles the keyset WHERE predicate for an N-key ordering as a DQL string
 * plus its bound parameters — pure data, no QueryBuilder. DQL has no row-value
 * (tuple) comparison, so the lexicographic "strictly past the boundary" test is
 * expanded into the nested form:
 *
 *   `(c1 > :v1) OR (c1 = :v1 AND ((c2 > :v2) OR (c2 = :v2 AND (id > :vid))))`
 *
 * Explicit parentheses are emitted at EVERY key level. Dynamic OR/AND
 * composition in DQL is ambiguous without grouping, so flattening that leans on
 * implicit operator precedence is forbidden — the grouping is part of the
 * contract and is asserted in the emitted string.
 *
 * Only the repository-authored column identifiers (qualified with the caller's
 * root alias) and generated parameter names are interpolated; every boundary
 * value travels as a bound parameter.
 *
 * Kernel-único: the builder holds no policy state. Each caller passes its
 * {@see WirePaginationPolicy} explicitly (boundary semantics), so it can never
 * be shared "without context" between the wire and batch policies.
 */
final readonly class KeysetPredicateBuilder
{
    private const string PARAMETER_PREFIX = 'keyset_';

    /**
     * @param array<string, mixed> $values boundary values keyed by ORDER BY column
     * @param string               $alias  the query's root alias; every bare column is qualified
     *                                      with it before emission, so the DQL is self-contained and
     *                                      needs no post-hoc rewrite by the caller
     *
     * @return array{dql: string, parameters: array<string, mixed>}
     */
    public function build(OrderByColumns $columns, array $values, WirePaginationPolicy $policy, string $alias): array
    {
        // Build inside-out: the tie-break (last column) is the innermost group.
        $reversed = \array_reverse($columns->all());

        $innermost = $reversed[0];
        $innerName = $this->parameterName($innermost['column']);
        $parameters = [$innerName => $this->boundaryValue($values, $innermost['column'])];
        $condition = \sprintf(
            '(%s %s :%s)',
            $this->qualify($innermost['column'], $alias),
            $this->comparison($innermost['direction'], $policy),
            $innerName,
        );

        foreach (\array_slice($reversed, 1) as $column) {
            $name = $this->parameterName($column['column']);
            $parameters[$name] = $this->boundaryValue($values, $column['column']);

            $qualified = $this->qualify($column['column'], $alias);
            $strict = \sprintf('%s %s :%s', $qualified, $this->comparison($column['direction'], $policy), $name);
            $equals = \sprintf('%s = :%s', $qualified, $name);
            $condition = \sprintf('((%s) OR (%s AND %s))', $strict, $equals, $condition);
        }

        return ['dql' => $condition, 'parameters' => $parameters];
    }

    /**
     * Qualifies a bare column with the root alias; an already-qualified `alias.col` path (a
     * repository that pre-qualifies its sort fields) is left untouched. The parameter name is derived
     * from the ORIGINAL identifier, never the qualified one, so the alias never perturbs the stable,
     * content-derived names that key Doctrine's SQL cache.
     */
    private function qualify(string $column, string $alias): string
    {
        return \str_contains($column, '.') ? $column : $alias . '.' . $column;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function boundaryValue(array $values, string $column): mixed
    {
        if (!\array_key_exists($column, $values)) {
            throw new InvalidArgumentException(
                \sprintf('Cursor is missing a boundary value for order-by column "%s".', $column),
            );
        }

        return $values[$column];
    }

    private function comparison(SortDirection $direction, WirePaginationPolicy $policy): string
    {
        $strict = SortDirection::ASC === $direction ? '>' : '<';

        return $policy->exclusiveBoundary ? $strict : $strict . '=';
    }

    private function parameterName(string $column): string
    {
        // Stable, content-derived name (same rationale as FilterApplier): a query
        // that recurs gets the same parameter names, so Doctrine reuses its SQL
        // cache instead of minting new entries. xxh128 is a fast digest, never a secret.
        return self::PARAMETER_PREFIX . \substr(\hash('xxh128', $column), 0, 16);
    }
}
