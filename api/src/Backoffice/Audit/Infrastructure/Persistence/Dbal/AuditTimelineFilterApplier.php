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

    /** Easternmost real-world UTC offset (UTC+14, e.g. Kiribati); east of it a bound is nonsensical. */
    private const int MAX_UTC_OFFSET_EAST_SECONDS = 14 * 3600;

    /** Westernmost real-world UTC offset (UTC-12, e.g. Baker Island); west of it a bound is nonsensical. */
    private const int MIN_UTC_OFFSET_WEST_SECONDS = -12 * 3600;

    /** The year PostgreSQL's calendar does not have; see {@see self::carriesAStorableYear()}. */
    private const string UNSTORABLE_YEAR = '0000';

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

        // The instance test subsumes createFromFormat's `false` and narrows the type for the gate
        // behind it.
        if (
            !$dateTime instanceof DateTimeImmutable
            || !$this->carriesAStorableYear($dateTime)
            || !$this->carriesRealWorldOffset($dateTime)
            || !$this->isCanonicalUnder($dateTime, $format, $value)
        ) {
            return null;
        }

        return $dateTime;
    }

    /**
     * PostgreSQL's calendar runs from 1 BC straight to 1 AD, so it has no year zero and rejects
     * `0000-…` with SQLSTATE 22008. PHP parses that year happily and it round-trips byte-identically,
     * so every other gate here passes it: measured, the bound reached the driver and surfaced as a 500
     * on input any client can send for free — the one value that falsified this parse's own promise of
     * a 422. A four-digit year makes it the only unstorable instant these formats can express;
     * `9999-12-31` stores fine, both measured against the running server.
     */
    private function carriesAStorableYear(DateTimeImmutable $dateTime): bool
    {
        return self::UNSTORABLE_YEAR !== $dateTime->format('Y');
    }

    /**
     * The real-world offset span is asymmetric (UTC-12 to UTC+14), so each side is checked separately; a
     * symmetric abs() would admit the non-existent -13/-14h offsets. PHP parses an offset all the way to
     * ±99:00 and every one of them round-trips canonically, so without this the bound is accepted and the
     * instant silently shifts by up to four days — and the same wire value would be a 422 on the shared
     * search path and a quietly wrong result set here. Twinned with
     * {@see \Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FilterApplier}: the two strict
     * parses have to answer alike, and this one lacked the gate until a review measured the divergence.
     */
    private function carriesRealWorldOffset(DateTimeImmutable $dateTime): bool
    {
        return $dateTime->getOffset() <= self::MAX_UTC_OFFSET_EAST_SECONDS
            && $dateTime->getOffset() >= self::MIN_UTC_OFFSET_WEST_SECONDS;
    }

    /**
     * Round-trip gate: the value is canonical under `$format` only if formatting the parsed instant
     * reproduces it byte-identically. UTC has two canonical spellings — `P` parses both but emits
     * `+00:00`, while `p` emits the literal `Z` a JS toISOString() sends — so either rendering of the
     * same instant is accepted.
     */
    private function isCanonicalUnder(DateTimeImmutable $dateTime, string $format, string $value): bool
    {
        return $dateTime->format($format) === $value
            || $dateTime->format(\str_replace('P', 'p', $format)) === $value;
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
