<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Doctrine\ORM\QueryBuilder;
use LogicException;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @template T of object
 *
 * @extends AbstractRepository<T>
 */
abstract class AbstractSearchRepository extends AbstractRepository
{
    private PaginatorCursorFactory $paginatorCursorFactory;

    #[Required]
    public function setPaginatorCursorFactory(PaginatorCursorFactory $paginatorCursorFactory): void
    {
        $this->paginatorCursorFactory = $paginatorCursorFactory;
    }

    /** @param array<string, mixed> $searchParameters */
    public function getPaginatedResults(array $searchParameters): Paginator
    {
        $queryBuilder = $this->getSearchQueryBuilder($searchParameters);

        $cursor = $searchParameters[QueryParam::CURSOR->value] ?? null;
        \assert(null === $cursor || \is_string($cursor));

        $paginationMode = $searchParameters[QueryParam::PAGINATION_MODE->value] ?? null;
        \assert(null === $paginationMode || $paginationMode instanceof PaginationMode);

        $page = $searchParameters[QueryParam::PAGE->value] ?? 1;
        \assert(\is_int($page) || \is_string($page) || \is_float($page));

        return $this->getQueryBuilderPaginatedResults(
            $queryBuilder,
            $this->paginatorCursorFactory->createFromString($cursor),
            (int) $page,
            $paginationMode,
        );
    }

    public function getQueryBuilderPaginatedResults(
        QueryBuilder $queryBuilder,
        PaginatorCursorInterface $cursor,
        int $page,
        ?PaginationMode $paginationMode = null,
    ): Paginator {
        $paginationMode ??= PaginationMode::LIGHT;

        $options = [];

        if ($queryBuilder instanceof QueryBuilderWithOptions) {
            $queryBuilder->setOption(PaginatorOption::PAGINATION_MODE->value, $paginationMode->value);
            $options = $queryBuilder->getOptions();
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

        if (1 < \count($idFieldNames)) {
            // Composite primary keys break Doctrine's default WHERE-IN paginator.
            // https://github.com/doctrine/orm/blob/3.3.x/src/Tools/Pagination/Paginator.php#L134
            $options[PaginatorOption::FETCH_JOIN_COLLECTION->value] = false;
        }

        return new Paginator(
            $queryBuilder,
            $cursor,
            $idFieldNames,
            $options,
            $page,
            $queryBuilder->getMaxResults() ?? 500,
        );
    }

    protected function addOrderByFromQueryParams(
        QueryBuilder|QueryBuilderWithOptions $queryBuilder,
        string $alias,
        ?string $orderByField = QueryParam::CREATED_AT->value,
        ?SortDirection $direction = SortDirection::ASC,
    ): QueryBuilder {
        $sort = $orderByField ?? QueryParam::CREATED_AT->value;
        $order = $direction ?? SortDirection::ASC;

        return $queryBuilder->addOrderBy(
            sort: \sprintf('%s.%s', $alias, $sort),
            order: $order->value,
        );
    }

    /** @param array<string, mixed> $queryParams */
    abstract public function getSearchQueryBuilder(array $queryParams): QueryBuilder;
}
