<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search;

use Doctrine\ORM\QueryBuilder;
use Erpify\Shared\Domain\Search\Exception\InvalidSearchValue;
use Erpify\Shared\Domain\Search\Exception\UnknownSearchField;
use Erpify\Shared\Domain\Search\Exception\UnsupportedSearchOperator;
use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Domain\Search\FilterOperator;
use Erpify\Shared\Domain\Search\Filters;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

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
 */
final readonly class FilterApplier
{
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

        [$condition, $parameterValue] = match ($filter->operator) {
            FilterOperator::Eq => $this->eqCondition($mapping, $filter, $parameterName),
            FilterOperator::In => $this->inCondition($mapping, $filter, $parameterName),
            FilterOperator::Contains => $this->containsCondition($mapping, $filter, $parameterName),
        };

        $queryBuilder
            ->setParameter($parameterName, $parameterValue)
            ->andWhere($condition)
        ;
    }

    /**
     * @return array{string, string}
     */
    private function eqCondition(FieldMapping $mapping, Filter $filter, string $parameterName): array
    {
        return [
            \sprintf('%s = :%s', $mapping->dqlPath, $parameterName),
            $this->normalizedNotBlank($mapping, $this->scalarValue($filter), $filter->operator),
        ];
    }

    /**
     * @return array{string, non-empty-list<string>}
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
        ];
    }

    /**
     * @return array{string, string}
     */
    private function containsCondition(FieldMapping $mapping, Filter $filter, string $parameterName): array
    {
        $value = $this->normalizedNotBlank($mapping, $this->scalarValue($filter), $filter->operator);

        $pattern = '%' . $this->escapeLikeWildcards($value) . '%';

        if (!$mapping->normalizer instanceof FieldNormalizer) {
            return [\sprintf('LOWER(%s) LIKE LOWER(:%s)', $mapping->dqlPath, $parameterName), $pattern];
        }

        return [\sprintf('%s LIKE :%s', $mapping->dqlPath, $parameterName), $pattern];
    }

    /**
     * Normalizes the value with the field's normalizer and rejects results that are blank.
     * Unreachable from the wire (shape validation rejects blank values in mapping), so a blank
     * here is a programmer error: CONTAINS would degenerate into a match-everything `LIKE '%%'`,
     * EQ and IN into a meaningless empty-string predicate. Fail loudly instead.
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
