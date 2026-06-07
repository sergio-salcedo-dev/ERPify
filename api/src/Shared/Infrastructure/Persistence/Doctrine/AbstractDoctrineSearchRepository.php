<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\Query\Expr\Select;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Domain\Search\PaginationMode;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FilterApplier;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SearchFieldMap;
use Erpify\Shared\Infrastructure\Persistence\PaginatorCursorFactory;
use Erpify\Shared\Infrastructure\Persistence\PaginatorCursorInterface;
use Erpify\Shared\Infrastructure\Persistence\QueryParam;
use Erpify\Shared\Infrastructure\Persistence\SortDirection;
use LogicException;

/**
 * @template T of object
 *
 * @extends AbstractDoctrineRepository<T>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
abstract class AbstractDoctrineSearchRepository extends AbstractDoctrineRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly PaginatorCursorFactory $paginatorCursorFactory,
        private readonly FilterApplier $filterApplier,
    ) {
        parent::__construct($registry);
    }

    /**
     * Generic filters are auto-applied here, between the repository-authored query builder and
     * pagination — repositories only declare their allow-list via {@see SearchFieldMap()} and
     * never invoke the applier themselves. `getQueryBuilderPaginatedResults()` stays outside
     * the seam on purpose (callers passing an arbitrary query builder own its conditions).
     *
     * @return Paginator<T>
     */
    public function getPaginatedResults(SearchCriteria $criteria): Paginator
    {
        $queryBuilder = $this->getSearchQueryBuilder($criteria);

        $this->filterApplier->apply($queryBuilder, $criteria->filters, $this->searchFieldMap());

        return $this->getQueryBuilderPaginatedResults(
            $queryBuilder,
            $this->paginatorCursorFactory->createFromString($criteria->cursor),
            $criteria->page,
            $criteria->paginationMode,
        );
    }

    /**
     * Mandatory allow-list of publicly filterable fields for this repository. Return
     * `new SearchFieldMap([])` to expose no filterable field at all — anything outside the
     * map is rejected with a 400 by the applier.
     */
    abstract protected function searchFieldMap(): SearchFieldMap;

    /**
     * @return Paginator<T>
     */
    public function getQueryBuilderPaginatedResults(
        QueryBuilder $queryBuilder,
        PaginatorCursorInterface $cursor,
        int $page,
        PaginationMode $paginationMode = PaginationMode::LIGHT,
    ): Paginator {
        $options = [PaginatorOption::ENABLE_CURSOR_PAGINATION->value => true];

        if ($queryBuilder instanceof QueryBuilderWithOptions) {
            $queryBuilder->setOption(PaginatorOption::PAGINATION_MODE->value, $paginationMode->value);
            $options = [...$options, ...$queryBuilder->getOptions()];
        }

        $rootAliases = $queryBuilder->getRootAliases();

        if ([] === $rootAliases) {
            throw new LogicException('QueryBuilder must have at least one root alias.');
        }

        $alias = $rootAliases[0];
        $idFieldNames = \array_map(
            static fn (string $fieldName): string => $alias . '.' . $fieldName,
            $this->getClassMetadata()->getIdentifierFieldNames(),
        );

        if (1 < \count($idFieldNames) && !$this->hasNonRootSelect($queryBuilder, $alias)) {
            // Composite primary keys break Doctrine's default WHERE-IN paginator.
            // Only safe to disable when no joined entities/collections are SELECTed —
            // otherwise DoctrinePaginator::count() inflates due to cartesian rows.
            // https://github.com/doctrine/orm/blob/3.3.x/src/Tools/Pagination/Paginator.php#L134
            $options[PaginatorOption::FETCH_JOIN_COLLECTION->value] = false;
        }

        // Local variable + @var annotation is load-bearing: PHPStan can't infer
        // Paginator<T> from the constructor call alone, and the surrounding generic
        // contract (`@return Paginator<T>`) is what callers rely on for typed iteration.
        /** @var Paginator<T> $paginator */
        $paginator = new Paginator(
            $queryBuilder,
            $cursor,
            $idFieldNames,
            $options,
            $page,
            $queryBuilder->getMaxResults() ?? self::MAX_LIMIT,
        );

        return $paginator;
    }

    protected function addOrderByFromQueryParams(
        QueryBuilder|QueryBuilderWithOptions $queryBuilder,
        string $alias,
        ?string $orderByField,
        ?SortDirection $direction,
    ): QueryBuilder {
        $sort = $orderByField ?? QueryParam::CREATED_AT->value;
        $order = $direction ?? SortDirection::ASC;

        return $queryBuilder->addOrderBy(
            sort: \sprintf('%s.%s', $alias, $sort),
            order: $order->value,
        );
    }

    abstract public function getSearchQueryBuilder(SearchCriteria $criteria): QueryBuilder;

    private function hasNonRootSelect(QueryBuilder $queryBuilder, string $rootAlias): bool
    {
        $selectParts = $queryBuilder->getDQLPart('select');

        if (!\is_array($selectParts)) {
            return false;
        }

        foreach ($selectParts as $selectPart) {
            if (!$selectPart instanceof Select) {
                continue;
            }

            foreach ($selectPart->getParts() as $part) {
                if (\trim((string) $part) !== $rootAlias) {
                    return true;
                }
            }
        }

        return false;
    }
}
