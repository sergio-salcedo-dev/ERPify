<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Persistence\Dbal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Erpify\Shared\Search\Domain\Exception\InvalidSearchValue;
use Erpify\Shared\Search\Domain\Exception\UnknownSearchField;
use Erpify\Shared\Search\Domain\Exception\UnsupportedSearchOperator;
use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Domain\Filters;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use ValueError;

/**
 * Translates the generic {@see Filters} into DBAL `andWhere` conditions over the raw `audit_log`
 * table, governed by the repository's mandatory {@see SearchFieldMap} allow-list — the DBAL twin of
 * the ORM `FilterApplier`. The append-only audit table has no Doctrine entity, so this works the
 * physical columns directly: client input never reaches SQL except as a bound parameter, UUID
 * columns are pre-validated (a malformed id is a 422, not a Postgres 22P02 → 500), and datetime
 * range bounds are parsed strictly before binding as `CAST(:p AS TIMESTAMPTZ)`.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class AuditTimelineFilterApplier
{
    /**
     * Accepted range-bound formats, tried in order: ATOM (`+00:00`/`Z` at second precision), the
     * milli-second `toISOString()` a JS client emits, and the microsecond form the audit timeline
     * carries. `P` matches both an offset and `Z`, spanning the RFC 3339 surface without a lax parse
     * that would also accept "now"/"tomorrow".
     */
    private const array SUPPORTED_DATE_TIME_FORMATS = [
        DateTimeInterface::ATOM,
        DateTimeInterface::RFC3339_EXTENDED,
        'Y-m-d\TH:i:s.uP',
    ];

    public function apply(QueryBuilder $queryBuilder, Filters $filters, SearchFieldMap $fieldMap): void
    {
        $index = 0;

        foreach ($filters as $filter) {
            $mapping = $fieldMap->mappingFor($filter->field)
                ?? throw UnknownSearchField::named($filter->field);

            if (!$mapping->allows($filter->operator)) {
                throw UnsupportedSearchOperator::forField($filter->field, $filter->operator);
            }

            if ($mapping->requiresUuidValues) {
                $this->ensureUuidValues($filter);
            }

            $this->applyFilter($queryBuilder, $mapping, $filter, 'f' . $index);
            ++$index;
        }
    }

    private function applyFilter(QueryBuilder $queryBuilder, FieldMapping $mapping, Filter $filter, string $name): void
    {
        match ($filter->operator) {
            FilterOperator::Eq => $this->eq($queryBuilder, $mapping, $filter, $name),
            FilterOperator::In => $this->in($queryBuilder, $mapping, $filter, $name),
            FilterOperator::Contains => $this->contains($queryBuilder, $mapping, $filter, $name),
            FilterOperator::Gt => $this->range($queryBuilder, $mapping, $filter, $name, '>'),
            FilterOperator::Gte => $this->range($queryBuilder, $mapping, $filter, $name, '>='),
            FilterOperator::Lt => $this->range($queryBuilder, $mapping, $filter, $name, '<'),
            FilterOperator::Lte => $this->range($queryBuilder, $mapping, $filter, $name, '<='),
        };
    }

    private function eq(QueryBuilder $queryBuilder, FieldMapping $mapping, Filter $filter, string $name): void
    {
        // A UUID column compared against a text parameter has no Postgres operator: cast the bound
        // value (already format-validated by ensureUuidValues) so the comparison stays index-backed.
        $condition = $mapping->requiresUuidValues
            ? \sprintf('%s = CAST(:%s AS UUID)', $mapping->dqlPath, $name)
            : \sprintf('%s = :%s', $mapping->dqlPath, $name);

        $queryBuilder->andWhere($condition)->setParameter($name, $this->scalarValue($filter));
    }

    private function in(QueryBuilder $queryBuilder, FieldMapping $mapping, Filter $filter, string $name): void
    {
        $values = $filter->value;

        if (!\is_array($values) || [] === $values) {
            // Unreachable from the wire (shape validation rejects empty lists): fail loudly.
            throw new InvalidArgumentException('IN filter requires a non-empty list of values.');
        }

        $queryBuilder
            ->andWhere(\sprintf('%s IN (:%s)', $mapping->dqlPath, $name))
            ->setParameter($name, $values, ArrayParameterType::STRING)
        ;
    }

    private function contains(QueryBuilder $queryBuilder, FieldMapping $mapping, Filter $filter, string $name): void
    {
        $pattern = '%' . $this->escapeLikeWildcards($this->scalarValue($filter)) . '%';

        $queryBuilder
            ->andWhere(\sprintf('LOWER(%s) LIKE LOWER(:%s)', $mapping->dqlPath, $name))
            ->setParameter($name, $pattern)
        ;
    }

    private function range(
        QueryBuilder $queryBuilder,
        FieldMapping $mapping,
        Filter $filter,
        string $name,
        string $comparison,
    ): void {
        if (!$mapping->requiresDateTimeValues) {
            throw new InvalidArgumentException(
                \sprintf('%s filter requires a field declared with datetime values.', $filter->operator->name),
            );
        }

        $queryBuilder
            ->andWhere(\sprintf('%s %s CAST(:%s AS TIMESTAMPTZ)', $mapping->dqlPath, $comparison, $name))
            ->setParameter($name, $this->dateTimeBound($filter)->format('Y-m-d H:i:s.uP'))
        ;
    }

    private function ensureUuidValues(Filter $filter): void
    {
        $values = \is_array($filter->value) ? $filter->value : [$filter->value];

        foreach ($values as $position => $value) {
            if (!Uuid::isValid((string) $value)) {
                throw InvalidSearchValue::notAUuid($filter->field, (int) $position);
            }
        }
    }

    /**
     * Parses a range bound as a strict RFC 3339 / ISO-8601 datetime and normalizes it to UTC (the
     * timezone `occurred_on` is written in). Strict by a byte-identical round-trip:
     * `createFromFormat` tolerates non-canonical digit widths without a warning, so only a value that
     * re-renders to itself is accepted — trailing data, calendar rollover and defaulted fields all
     * shift the render away. A non-parseable value is client input → 422, never a Postgres 22007 → 500.
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

    private function parseStrict(string $format, string $value): ?DateTimeImmutable
    {
        try {
            $dateTime = DateTimeImmutable::createFromFormat($format, $value);
        } catch (ValueError) {
            return null;
        }

        if (!$dateTime instanceof DateTimeImmutable) {
            return null;
        }

        // UTC has two canonical spellings: `P` parses both but emits `+00:00`, while `p` emits the
        // literal `Z` a JS toISOString() sends — accept either rendering of the same instant.
        if ($dateTime->format($format) !== $value && $dateTime->format(\str_replace('P', 'p', $format)) !== $value) {
            return null;
        }

        return $dateTime;
    }

    private function scalarValue(Filter $filter): string
    {
        if (!\is_string($filter->value)) {
            throw new InvalidArgumentException(
                \sprintf('%s filter requires a scalar string value.', $filter->operator->name),
            );
        }

        return $filter->value;
    }

    private function escapeLikeWildcards(string $value): string
    {
        // Backslash first: it is Postgres' default LIKE escape, so a literal one must not turn the
        // following %/_ escape into plain text.
        return \str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
