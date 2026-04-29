<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Doctrine\ORM\Query\Expr\Select;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Domain\Search\PaginationMode;
use InvalidArgumentException;
use LogicException;

/**
 * @template T of object
 *
 * @extends AbstractRepository<T>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
abstract class AbstractSearchRepository extends AbstractRepository
{
    final public const int MAX_PAGE = 10_000;

//    final public const int MAX_LIMIT = 1_000;

    public function __construct(
        ManagerRegistry $registry,
        private readonly PaginatorCursorFactory $paginatorCursorFactory,
    ) {
        parent::__construct($registry);
    }

    /** @param array<string, mixed> $queryParams */
    public function getPaginatedResults(array $queryParams): Paginator
    {
        $queryBuilder = $this->getSearchQueryBuilder($queryParams);

        $cursor = $queryParams[QueryParam::CURSOR->value] ?? null;
        \assert(null === $cursor || \is_string($cursor));

        $paginationMode = $queryParams[QueryParam::PAGINATION_MODE->value] ?? null;
        \assert(null === $paginationMode || $paginationMode instanceof PaginationMode);

        $page = $queryParams[QueryParam::PAGE->value] ?? 1;

        $pageInt = match (true) {
            \is_int($page) => $page,
            \is_string($page) && \ctype_digit($page) => (int) $page,
            default => throw new InvalidArgumentException(\sprintf(
                'Page must be a positive integer, got "%s".',
                \is_scalar($page) ? (string) $page : \get_debug_type($page),
            )),
        };

        $pageInt = \max(1, \min(self::MAX_PAGE, $pageInt));

        $limit = $queryParams[QueryParam::LIMIT->value] ?? self::MAX_LIMIT;
        $limitInt = match (true) {
            \is_int($limit) => $limit,
            \is_string($limit) && \ctype_digit($limit) => (int) $limit,
            default => throw new InvalidArgumentException(\sprintf(
                'Limit must be a positive integer, got "%s".',
                \is_scalar($limit) ? (string) $limit : \get_debug_type($limit),
            )),
        };

        $limitInt = \max(1, \min(self::MAX_LIMIT, $limitInt));

        $this->addLimit($queryBuilder, $limitInt);

        return $this->getQueryBuilderPaginatedResults(
            $queryBuilder,
            $this->paginatorCursorFactory->createFromString($cursor),
            $pageInt,
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

    /** @param array<string, mixed> $queryParams */
    abstract public function getSearchQueryBuilder(array $queryParams): QueryBuilder;

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
