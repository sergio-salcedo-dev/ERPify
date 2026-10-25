<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\QueryBuilder;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\PaginationMode;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\CursorPositionExtractor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\KeysetPredicateBuilder;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\OrderByColumns;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\KeysetSource;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\WirePaginationPolicy;
use Override;

/**
 * The ORM side of the keyset walk: one DQL query, already filtered, sorted and fingerprinted by
 * {@see DoctrineSearchEngine}, read through the nested {@see KeysetPredicateBuilder} predicate (DQL has no
 * row-value comparison) and positioned by the {@see CursorPositionExtractor} at column precision.
 *
 * The filtered builder it holds is never mutated: each fetch and the COUNT run on a clone, so the COUNT sees
 * the filtered query with no keyset predicate, order or limit.
 *
 * @implements KeysetSource<object, object>
 */
final readonly class DoctrineKeysetSource implements KeysetSource
{
    /**
     * @param QueryBuilder $queryBuilder the base query with the request filters applied, nothing else
     * @param string       $alias        the base query's root alias, qualifying every bare ordering column
     * @param class-string $rootEntity   the root entity, whose single identifier the COUNT counts
     */
    public function __construct(
        private QueryBuilder $queryBuilder,
        private string $alias,
        private string $rootEntity,
        private OrderByColumns $orderByColumns,
        private string $fingerprint,
        private int $limit,
        private WirePaginationPolicy $policy,
        private PaginationMode $paginationMode,
        private KeysetPredicateBuilder $predicateBuilder,
        private CursorPositionExtractor $positionExtractor,
    ) {
    }

    #[Override]
    public function boundaryColumns(): array
    {
        return $this->orderByColumns->columnNames();
    }

    #[Override]
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    #[Override]
    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * `before` inverts every key's direction in SQL; the boundary values stay keyed by the original columns,
     * which the inverted list keeps in place, so the same predicate builder serves both directions.
     */
    #[Override]
    public function fetch(?array $boundary, NavigationDirection $direction, int $maxResults): array
    {
        $physicalColumns = NavigationDirection::Before === $direction
            ? $this->invert($this->orderByColumns)
            : $this->orderByColumns;

        $fetch = clone $this->queryBuilder;
        $this->applyKeysetPredicate($fetch, $physicalColumns, $boundary);
        $this->applyOrderBy($fetch, $physicalColumns);
        $fetch->setMaxResults($maxResults);

        /** @var list<object> */
        return $fetch->getQuery()->getResult();
    }

    /**
     * @param object $row
     */
    #[Override]
    public function position(mixed $row): array
    {
        return $this->positionExtractor->extract($this->orderByColumns, $row);
    }

    /**
     * @param object $row
     */
    #[Override]
    public function item(mixed $row): object
    {
        return $row;
    }

    /** Detailed mode runs one COUNT over the filtered query (no order/limit/predicate); light skips it. */
    #[Override]
    public function count(): ?int
    {
        if (PaginationMode::DETAILED !== $this->paginationMode) {
            return null;
        }

        $identifier = $this->queryBuilder->getEntityManager()
            ->getClassMetadata($this->rootEntity)
            ->getSingleIdentifierFieldName()
        ;

        $countQuery = (clone $this->queryBuilder)
            ->select(\sprintf('COUNT(%s.%s)', $this->alias, $identifier))
            ->resetDQLPart('orderBy')
            ->setFirstResult(null)
            ->setMaxResults(null)
            ->getQuery()
        ;

        return (int) $countQuery->getSingleScalarResult();
    }

    /**
     * The builder qualifies every bare column (e.g. the `id` tie-break) with the root alias as it emits, so the
     * DQL is bound here as-is.
     *
     * @param array<string, mixed>|null $boundary
     */
    private function applyKeysetPredicate(QueryBuilder $queryBuilder, OrderByColumns $columns, ?array $boundary): void
    {
        if (null === $boundary) {
            return;
        }

        $predicate = $this->predicateBuilder->build($columns, $boundary, $this->policy, $this->alias);

        $queryBuilder->andWhere($predicate['dql']);

        foreach ($predicate['parameters'] as $name => $value) {
            $queryBuilder->setParameter($name, $value);
        }
    }

    private function applyOrderBy(QueryBuilder $queryBuilder, OrderByColumns $columns): void
    {
        // why: ordering is the engine's monopoly (the base QB contract is SELECT/FROM/joins only). A
        // stray ORDER BY from the repo would, via addOrderBy, sit AHEAD of the keyset columns and become
        // the primary sort — silently breaking the monotonic walk. Reset it first, as count() does.
        $queryBuilder->resetDQLPart('orderBy');

        foreach ($columns->all() as $column) {
            $queryBuilder->addOrderBy(
                $this->qualifyColumn($column['column']),
                DoctrineSortDirection::from($column['direction']),
            );
        }
    }

    /** Inverts every key direction; the `id` tie-break stays last, so arity is preserved (no re-append). */
    private function invert(OrderByColumns $columns): OrderByColumns
    {
        return OrderByColumns::fromSorts(\array_map(
            static fn (array $column): array => [
                'column' => $column['column'],
                'direction' => SortDirection::ASC === $column['direction'] ? SortDirection::DESC : SortDirection::ASC,
            ],
            $columns->all(),
        ));
    }

    private function qualifyColumn(string $column): string
    {
        return \str_contains($column, '.') ? $column : $this->alias . '.' . $column;
    }
}
