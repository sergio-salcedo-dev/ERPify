<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search;

use Doctrine\ORM\QueryBuilder;
use Erpify\Shared\Domain\Search\Exception\UnknownSearchField;
use Erpify\Shared\Domain\Search\Exception\UnsupportedSearchOperator;
use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Domain\Search\FilterOperator;
use Erpify\Shared\Domain\Search\Filters;
use InvalidArgumentException;

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

            $this->applyFilter($queryBuilder, $mapping, $filter);
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
            $this->normalize($mapping, $this->scalarValue($filter)),
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
            \array_map(fn (string $value): string => $this->normalize($mapping, $value), $values),
        ];
    }

    /**
     * @return array{string, string}
     */
    private function containsCondition(FieldMapping $mapping, Filter $filter, string $parameterName): array
    {
        $value = $this->normalize($mapping, $this->scalarValue($filter));

        if ('' === \trim($value)) {
            // Unreachable from the wire (shape validation rejects blank values in mapping):
            // `LIKE '%%'` would silently match every row, so fail loudly instead.
            throw new InvalidArgumentException('CONTAINS filter value must not normalize to an empty string.');
        }

        $pattern = '%' . $this->escapeLikeWildcards($value) . '%';

        if (!$mapping->normalizer instanceof FieldNormalizer) {
            return [\sprintf('LOWER(%s) LIKE LOWER(:%s)', $mapping->dqlPath, $parameterName), $pattern];
        }

        return [\sprintf('%s LIKE :%s', $mapping->dqlPath, $parameterName), $pattern];
    }

    private function normalize(FieldMapping $mapping, string $value): string
    {
        return $mapping->normalizer?->normalize($value) ?? $value;
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
