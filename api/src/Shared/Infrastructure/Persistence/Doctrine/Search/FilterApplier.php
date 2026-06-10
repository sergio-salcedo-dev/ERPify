<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Erpify\Shared\Domain\Search\Exception\InvalidSearchValue;
use Erpify\Shared\Domain\Search\Exception\UnknownSearchField;
use Erpify\Shared\Domain\Search\Exception\UnsupportedSearchOperator;
use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Domain\Search\FilterOperator;
use Erpify\Shared\Domain\Search\Filters;
use Erpify\Shared\Domain\Uuid\Uuid;
use InvalidArgumentException;
use ValueError;

/**
 * Translates domain {@see Filters} into `andWhere` conditions with bound parameters, governed
 * by the repository's mandatory {@see SearchFieldMap} allow-list — the required parameter makes
 * it impossible to filter without one. Only conditions are added here: pagination, ordering,
 * joins and COUNT remain the monopoly of the Paginator and each repository's
 * `getSearchQueryBuilder()`.
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
     * Accepted range-bound formats, tried in order. ATOM covers the `+00:00` offset and the `Z`
     * forms at second precision; the two fractional variants accept the milli-/microsecond
     * precision a JS `Date.prototype.toISOString()` (millis + `Z`) or a higher-precision client
     * emits. `P` matches both an offset and `Z`, so these three span the RFC 3339 surface
     * without falling back to a lax parse that would also accept "now"/"tomorrow".
     */
    private const array SUPPORTED_DATE_TIME_FORMATS = [
        DateTimeInterface::ATOM,
        DateTimeInterface::RFC3339_EXTENDED,
        'Y-m-d\TH:i:s.uP',
    ];

    /** Easternmost real-world UTC offset (UTC+14, e.g. Kiribati); east of it a bound is nonsensical. */
    private const int MAX_UTC_OFFSET_EAST_SECONDS = 14 * 3600;

    /** Westernmost real-world UTC offset (UTC-12, e.g. Baker Island); west of it a bound is nonsensical. */
    private const int MIN_UTC_OFFSET_WEST_SECONDS = -12 * 3600;

    public function apply(QueryBuilder $queryBuilder, Filters $filters, SearchFieldMap $fieldMap): void
    {
        if ($filters->isEmpty()) {
            return;
        }

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
        }
    }

    /**
     * Pre-validates values bound against UUID columns: Postgres rejects a malformed uuid with
     * 22P02 at execution time, which would surface as a 500 — but a bad uuid is client input,
     * so it must be a 400 from the invalid-search-criteria family instead.
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
     * it surfaces as a 400 from the invalid-search-criteria family instead of a Postgres
     * 22007/22008 turned 500.
     */
    private function dateTimeBound(Filter $filter): DateTimeImmutable
    {
        $value = $this->scalarValue($filter);

        foreach (self::SUPPORTED_DATE_TIME_FORMATS as $format) {
            $dateTime = $this->parseStrict($format, $value);

            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime->setTimezone(new DateTimeZone('UTC'));
            }
        }

        throw InvalidSearchValue::notADateTime($filter->field, 0);
    }

    /**
     * Strict single-format parse: returns the datetime only when the value matches the format
     * exactly (no trailing data, no warnings) and carries a real-world UTC offset. A value with
     * a null byte makes `createFromFormat` throw a `ValueError`; that is client input too, so it
     * is caught and reported as a 400 rather than escaping as an engine 500.
     */
    private function parseStrict(string $format, string $value): ?DateTimeImmutable
    {
        try {
            $dateTime = DateTimeImmutable::createFromFormat($format, $value);
        } catch (ValueError) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        $hasParseProblem = false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

        // `false === $dateTime` is kept first so the offset read only runs on a real instant.
        // The real-world offset span is asymmetric (UTC-12 to UTC+14), so each side is checked
        // separately; a symmetric abs() would admit the non-existent -13/-14h offsets.
        if (
            false === $dateTime
            || $hasParseProblem
            || $dateTime->getOffset() > self::MAX_UTC_OFFSET_EAST_SECONDS
            || $dateTime->getOffset() < self::MIN_UTC_OFFSET_WEST_SECONDS
        ) {
            return null;
        }

        return $dateTime;
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
        // Same stable naming scheme as AbstractDoctrineRepository::generateUniqueParameterName
        // (private there; the applier is not a repository): deriving the name from the current
        // DQL + parameter count keeps it consistent across requests so Doctrine reuses SQL
        // cache files instead of minting ever-new ones. xxh128 is a fast non-cryptographic
        // digest — it never guards a secret.
        return 'p' . \hash('xxh128', $queryBuilder->getDQL()) . \count($queryBuilder->getParameters());
    }
}
