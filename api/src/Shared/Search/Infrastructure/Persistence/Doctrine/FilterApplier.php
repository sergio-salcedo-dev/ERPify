<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Erpify\Shared\Search\Domain\Exception\InvalidSearchValue;
use Erpify\Shared\Search\Domain\Exception\UnknownSearchField;
use Erpify\Shared\Search\Domain\Exception\UnsupportedSearchOperator;
use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Domain\Filters;
use Erpify\Shared\Search\Domain\StrictRangeBound;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\AppliedFilters;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;

/**
 * Translates domain {@see Filters} into `andWhere` conditions with bound parameters, governed
 * by the repository's mandatory {@see SearchFieldMap} allow-list — the required parameter makes
 * it impossible to filter without one. Only conditions are added here: pagination, ordering,
 * joins and COUNT remain the monopoly of the {@see DoctrineSearchEngine} and each repository's
 * base query builder.
 *
 * Client input is never interpolated into DQL: the only interpolated fragments are the map's
 * `dqlPath` (repository-authored) and the generated parameter name; values always travel as
 * bound parameters, with `%`/`_` escaped for CONTAINS so a search value cannot become an
 * arbitrary LIKE pattern.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class FilterApplier
{
    /**
     * Applies the allow-listed filters to the query builder and returns the receipt of what was
     * actually applied — the {@see AppliedFilters} that feed step 4 of the engine pipeline (the
     * sealed {@see Keyset\QueryExecutionTrace}) and therefore the cursor fingerprint (AR22). The
     * receipt is the post-allow-list truth, never the raw request: every filter either passes the
     * map and is translated, or throws, so a drift between "requested" and "applied" can never
     * silently corrupt a cursor. The mutation of the query builder (`andWhere` + binds, LIKE
     * escaping) is unchanged — only the return type is widened from `void`.
     */
    public function apply(QueryBuilder $queryBuilder, Filters $filters, SearchFieldMap $fieldMap): AppliedFilters
    {
        if ($filters->isEmpty()) {
            return AppliedFilters::none();
        }

        $applied = [];

        foreach ($filters as $filter) {
            $mapping = $fieldMap->mappingFor($filter->field)
                ?? throw UnknownSearchField::named($filter->field);

            if (!$mapping->allows($filter->operator)) {
                throw UnsupportedSearchOperator::forField($filter->field, $filter->operator);
            }

            if ($mapping->requiresUuidValues) {
                $this->ensureUuidValues($filter);
            }

            $this->applyFilter($queryBuilder, $mapping, $filter);
            $applied[] = $filter;
        }

        return new AppliedFilters(...$applied);
    }

    /**
     * Pre-validates values bound against UUID columns: Postgres rejects a malformed uuid with
     * 22P02 at execution time, which would surface as a 500 — but a bad uuid is client input,
     * so it must be a 422 from the invalid-search-criteria family instead.
     */
    private function ensureUuidValues(Filter $filter): void
    {
        $values = \is_array($filter->value) ? $filter->value : [$filter->value];

        foreach ($values as $position => $value) {
            if (!Uuid::isValid($value)) {
                throw InvalidSearchValue::notAUuid($filter->field, $position);
            }
        }
    }

    private function applyFilter(QueryBuilder $queryBuilder, FieldMapping $mapping, Filter $filter): void
    {
        $parameterName = $this->uniqueParameterName($queryBuilder);

        // Each branch returns a [condition, value, ?type] tuple: the third element is the
        // Doctrine parameter type, null for the untyped string operators and
        // Types::DATETIME_IMMUTABLE for the temporal range operators.
        [$condition, $parameterValue, $parameterType] = match ($filter->operator) {
            FilterOperator::Eq => $this->eqCondition($mapping, $filter, $parameterName),
            FilterOperator::In => $this->inCondition($mapping, $filter, $parameterName),
            FilterOperator::Contains => $this->containsCondition($mapping, $filter, $parameterName),
            FilterOperator::Gt => $this->rangeCondition($mapping, $filter, $parameterName, '>'),
            FilterOperator::Gte => $this->rangeCondition($mapping, $filter, $parameterName, '>='),
            FilterOperator::Lt => $this->rangeCondition($mapping, $filter, $parameterName, '<'),
            FilterOperator::Lte => $this->rangeCondition($mapping, $filter, $parameterName, '<='),
        };

        $queryBuilder
            ->setParameter($parameterName, $parameterValue, $parameterType)
            ->andWhere($condition)
        ;
    }

    /**
     * @return array{string, string, null}
     */
    private function eqCondition(FieldMapping $mapping, Filter $filter, string $parameterName): array
    {
        return [
            \sprintf('%s = :%s', $mapping->dqlPath, $parameterName),
            $this->normalizedNotBlank($mapping, $this->scalarValue($filter), $filter->operator),
            null,
        ];
    }

    /**
     * @return array{string, non-empty-list<string>, null}
     */
    private function inCondition(FieldMapping $mapping, Filter $filter, string $parameterName): array
    {
        $values = $filter->value;

        if (!\is_array($values) || [] === $values) {
            // Unreachable from the wire (shape validation rejects empty lists in mapping):
            // an empty IN here is a programmer error, so fail loudly instead of silently
            // dropping the filter or emitting broken SQL.
            throw new InvalidArgumentException('IN filter requires a non-empty list of values.');
        }

        return [
            \sprintf('%s IN (:%s)', $mapping->dqlPath, $parameterName),
            \array_map(
                fn (string $value): string => $this->normalizedNotBlank($mapping, $value, $filter->operator),
                $values,
            ),
            null,
        ];
    }

    /**
     * @return array{string, string, null}
     */
    private function containsCondition(FieldMapping $mapping, Filter $filter, string $parameterName): array
    {
        $value = $this->normalizedNotBlank($mapping, $this->scalarValue($filter), $filter->operator);

        $pattern = '%' . $this->escapeLikeWildcards($value) . '%';

        if (!$mapping->normalizer instanceof FieldNormalizer) {
            return [\sprintf('LOWER(%s) LIKE LOWER(:%s)', $mapping->dqlPath, $parameterName), $pattern, null];
        }

        return [\sprintf('%s LIKE :%s', $mapping->dqlPath, $parameterName), $pattern, null];
    }

    /**
     * Temporal range branch: emits `path <op> :param` and binds the bound as a typed
     * `datetime_immutable` parameter. Binding a raw string against a `timestamp` column has no
     * Postgres operator and would surface as a 500, so the value is always parsed and typed.
     *
     * Range operators are only ever wired onto fields the repository marked
     * `requiresDateTimeValues`; reaching one without that flag is a field-map misconfiguration,
     * so it fails loudly as a programmer error rather than binding an untyped string.
     *
     * @return array{string, DateTimeImmutable, string}
     */
    private function rangeCondition(
        FieldMapping $mapping,
        Filter $filter,
        string $parameterName,
        string $comparison,
    ): array {
        if (!$mapping->requiresDateTimeValues) {
            throw new InvalidArgumentException(
                \sprintf('%s filter requires a field declared with datetime values.', $filter->operator->name),
            );
        }

        return [
            \sprintf('%s %s :%s', $mapping->dqlPath, $comparison, $parameterName),
            $this->dateTimeBound($filter),
            Types::DATETIME_IMMUTABLE,
        ];
    }

    /**
     * Parses a range bound as an RFC 3339 / ISO-8601 datetime and normalizes it to UTC — the
     * timezone the timestamp columns are written in (see AggregateRoot). The offset (`+00:00`)
     * and `Z` forms are accepted, with optional fractional seconds, so the canonical
     * `toISOString()` output a JS client emits is a first-class value. Parsing stays strict
     * otherwise: a lax `new DateTimeImmutable($value)` would accept "now"/"tomorrow", a
     * date-only string has no wall-clock to compare, and an out-of-range offset would shift the
     * instant past any real timezone — all rejected. A non-parseable value is client input, so
     * it surfaces as a 422 from the invalid-search-criteria family instead of a Postgres
     * 22007/22008 turned 500.
     */
    private function dateTimeBound(Filter $filter): DateTimeImmutable
    {
        return StrictRangeBound::parse($this->scalarValue($filter))
            ?? throw InvalidSearchValue::notADateTime($filter->field, 0);
    }

    /**
     * Normalizes the value with the field's normalizer and rejects results that are blank.
     * The domain {@see Filter} constructor rejects A raw-blank value (empty after a Unicode-aware trim)
     * before the applier runs, and the wire DTO rejects it earlier still.
     * This guard remains as defense in depth for a field normalizer that folds a non-blank value
     * to an empty string: a programmer error, since CONTAINS would degenerate into a match-everything
     * `LIKE '%%'` and EQ/IN into a meaningless empty-string predicate. Fail loudly instead.
     */
    private function normalizedNotBlank(FieldMapping $mapping, string $value, FilterOperator $operator): string
    {
        $normalized = $mapping->normalizer?->normalize($value) ?? $value;

        if ('' === \trim($normalized)) {
            throw new InvalidArgumentException(
                \sprintf('%s filter value must not normalize to an empty string.', $operator->name),
            );
        }

        return $normalized;
    }

    private function scalarValue(Filter $filter): string
    {
        $value = $filter->value;

        if (!\is_string($value)) {
            throw new InvalidArgumentException(
                \sprintf('%s filter requires a scalar string value.', $filter->operator->name),
            );
        }

        return $value;
    }

    private function escapeLikeWildcards(string $value): string
    {
        // Backslash first: it is Postgres' default LIKE escape character, so a literal one in
        // the search value must not turn the following %/_ escape into plain text.
        return \str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function uniqueParameterName(QueryBuilder $queryBuilder): string
    {
        // A generated parameter name must be STABLE across executions of the same logical query:
        // deriving it from the current DQL + parameter count keeps it consistent across requests so
        // Doctrine reuses SQL cache files instead of minting an ever-new one per request (an unstable
        // name grows the cache until it exhausts disk). xxh128 is a fast non-cryptographic digest —
        // it never guards a secret.
        return 'p' . \hash('xxh128', $queryBuilder->getDQL()) . \count($queryBuilder->getParameters());
    }
}
