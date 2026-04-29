<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use ArrayIterator;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Erpify\Shared\Domain\Search\PaginationMode;
use InvalidArgumentException;
use Iterator;
use IteratorAggregate;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Traversable;

/**
 * Cursor-aware paginator. Stripped down port of chiliz/doctrine-bundle Paginator.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 *
 * @implements IteratorAggregate<int, mixed>
 */
final class Paginator implements IteratorAggregate
{
    /**
     * Allow-list pattern for order-by identifiers safely interpolated into DQL.
     * Matches `alias.field`, `field`, with optional underscore-prefixed segments.
     */
    private const string ORDER_BY_IDENTIFIER_PATTERN = '/^[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*$/';

    private ?Iterator $iterator = null;

    private bool $hasMorePages = false;

    /**
     * @param array<int, string>   $idFields
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly QueryBuilder $queryBuilder,
        private readonly PaginatorCursorInterface $paginatorCursor,
        private readonly array $idFields,
        private array $options,
        private readonly int $currentPage = 1,
        private readonly int $maxPerPage = 10,
    ) {
    }

    public function getIterator(): Traversable
    {
        if ($this->iterator instanceof Iterator) {
            return $this->iterator;
        }

        $queryBuilder = clone $this->queryBuilder;
        $countBaseline = clone $queryBuilder;
        $queryBuilder = $this->alterQueryBuilder($queryBuilder);
        $this->resetCursor($queryBuilder);

        $columns = \array_keys($this->getOrderByColumns($queryBuilder));
        $query = $this->getQuery($queryBuilder);

        $doctrinePaginator = new DoctrinePaginator($query, $this->isFetchingJoinCollection());

        $results = [];
        $noOfResults = 0;
        $lastItem = null;

        foreach ($doctrinePaginator->getIterator() as $item) {
            if (null === $lastItem) {
                $this->paginatorCursor->setFirstItem($this->extractFields($columns, $item));
            }

            ++$noOfResults;

            if ($noOfResults > $queryBuilder->getMaxResults()) {
                $this->hasMorePages = true;

                break;
            }

            $lastItem = $item;
            $results[] = $item;
        }

        if ($this->shouldCalculateNumberOfRecords()) {
            $this->setCursorCount($countBaseline, $results);
        }

        if (null !== $lastItem) {
            $this->paginatorCursor->setLastItem($this->extractFields($columns, $lastItem));
        }

        $this->paginatorCursor->setCurrentPage($this->currentPage);

        return $this->iterator = new ArrayIterator($results);
    }

    /** @param array<int, mixed> $results */
    private function setCursorCount(QueryBuilder $queryBuilder, array $results): void
    {
        if (null !== $this->paginatorCursor->getCount()) {
            return;
        }

        $countQuery = $queryBuilder->resetDQLPart('orderBy')->getQuery();
        $countDoctrinePaginator = new DoctrinePaginator($countQuery, $this->isFetchingJoinCollection());

        $this->paginatorCursor->setCount(
            $this->isSingleFirstPageQuery($results) ? \count($results) : $countDoctrinePaginator->count(),
        );
    }

    public function hasMorePages(): bool
    {
        return $this->hasMorePages;
    }

    public function shouldCalculateNumberOfRecords(): bool
    {
        return PaginationMode::DETAILED->value === ($this->options[PaginatorOption::PAGINATION_MODE->value] ?? null);
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function getPageCount(): ?int
    {
        $count = $this->paginatorCursor->getCount();

        if (null === $count || $this->maxPerPage <= 0) {
            return null;
        }

        return (int) \ceil($count / $this->maxPerPage);
    }

    public function getCursor(): PaginatorCursorInterface
    {
        return $this->paginatorCursor;
    }

    private function getQuery(QueryBuilder $queryBuilder): Query
    {
        $query = $queryBuilder->getQuery();
        $query->setMaxResults(((int) $query->getMaxResults()) + 1);

        return $query;
    }

    private function alterQueryBuilder(QueryBuilder $queryBuilder): QueryBuilder
    {
        $queryBuilder->setMaxResults($this->maxPerPage);

        foreach ($this->idFields as $idField) {
            if (!isset($this->getOrderByColumns($queryBuilder)[$idField])) {
                $queryBuilder->addOrderBy($idField, SortDirection::ASC->value);
            }
        }

        $hasOptimizedWhere = $this->alterWhere($queryBuilder);
        $this->alterOffset($queryBuilder, $hasOptimizedWhere);

        return $queryBuilder;
    }

    /**
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    private function alterWhere(QueryBuilder $queryBuilder): bool
    {
        if (null === $this->paginatorCursor->getCurrentPage() || !$this->isCursorPaginationEnabled()) {
            return false;
        }

        $fields = $this->paginatorCursor->getLastItem();
        $goToNextPage = true;
        $isIncluded = false;

        if ($this->paginatorCursor->getCurrentPage() === $this->currentPage) {
            $fields = $this->paginatorCursor->getFirstItem();
            $isIncluded = true;
        }

        if ($this->currentPage < $this->paginatorCursor->getCurrentPage()) {
            $fields = $this->paginatorCursor->getFirstItem();
            $goToNextPage = false;
        }

        $condition = null;
        $parameters = [];

        foreach (\array_reverse($this->getOrderByColumns($queryBuilder)) as $orderBy => $orderByDirection) {
            if (!isset($fields[$orderBy])) {
                return false;
            }

            $parameter = ':pagination_' . \substr(\hash('xxh128', $orderBy), 0, 16);
            $parameters[] = ['parameter' => $parameter, 'orderBy' => $fields[$orderBy]];

            $newStrictCondition = \sprintf(
                '%s %s %s',
                $orderBy,
                $this->getWhereOperator($goToNextPage, $orderByDirection, $isIncluded),
                $parameter,
            );

            if (null === $condition) {
                $condition = $newStrictCondition;

                continue;
            }

            $newEqualsCondition = \sprintf('%s = %s', $orderBy, $parameter);
            $condition = \sprintf('(%s OR (%s AND %s))', $newStrictCondition, $newEqualsCondition, $condition);
        }

        if (null === $condition) {
            return false;
        }

        $queryBuilder->andWhere($condition);

        foreach ($parameters as $parameter) {
            $queryBuilder->setParameter($parameter['parameter'], $parameter['orderBy']);
        }

        return true;
    }

    private function getWhereOperator(bool $goToNextPage, string $direction, bool $isIncluded): string
    {
        $operator = 'asc' === $direction ? '<' : '>';

        if ($goToNextPage) {
            $operator = 'asc' === $direction ? '>' : '<';
        }

        return $isIncluded ? $operator . '=' : $operator;
    }

    private function alterOffset(QueryBuilder $queryBuilder, bool $hasOptimizedWhere): void
    {
        $pageDiff = $this->currentPage;

        if (
            $hasOptimizedWhere
            && null !== $this->paginatorCursor->getCurrentPage()
            && $this->currentPage >= $this->paginatorCursor->getCurrentPage()
        ) {
            $pageDiff -= $this->paginatorCursor->getCurrentPage();
        }

        if (0 === $pageDiff) {
            $queryBuilder->setFirstResult(0);

            return;
        }

        $queryBuilder->setFirstResult(($pageDiff - 1) * (int) $queryBuilder->getMaxResults());
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function alterCursorFields(QueryBuilder $queryBuilder, array $fields): array
    {
        foreach (\array_keys($this->getOrderByColumns($queryBuilder)) as $orderBy) {
            if (isset($fields[$orderBy])) {
                continue;
            }

            $fields[$orderBy] = null;
        }

        return $fields;
    }

    private function resetCursor(QueryBuilder $queryBuilder): void
    {
        $this->paginatorCursor->setFirstItem(
            $this->alterCursorFields($queryBuilder, $this->paginatorCursor->getFirstItem()),
        );
        $this->paginatorCursor->setLastItem(
            $this->alterCursorFields($queryBuilder, $this->paginatorCursor->getLastItem()),
        );
    }

    /** @return array<string, string> */
    private function getOrderByColumns(QueryBuilder $queryBuilder): array
    {
        $columns = [];

        if (!$this->isCursorPaginationEnabled()) {
            return $columns;
        }

        $orderByParts = $queryBuilder->getDQLPart('orderBy');

        if (!\is_array($orderByParts)) {
            return $columns;
        }

        foreach ($orderByParts as $order) {
            if (!$order instanceof OrderBy) {
                continue;
            }

            foreach ($order->getParts() as $part) {
                $matches = [];
                \preg_match('{^(?P<clause>.+?)(?:\s+(?P<dir>asc|desc))?$}i', $part, $matches);

                if (\array_key_exists('clause', $matches)) {
                    $clause = \trim($matches['clause']);

                    if (1 !== \preg_match(self::ORDER_BY_IDENTIFIER_PATTERN, $clause)) {
                        throw new InvalidArgumentException(
                            \sprintf('Unsafe order-by identifier "%s" rejected by paginator allow-list.', $clause),
                        );
                    }

                    $columns[$clause] = \strtolower($matches['dir'] ?? 'asc');
                }
            }
        }

        return $columns;
    }

    /**
     * @param array<int, string> $columns
     *
     * @return array<string, mixed>
     */
    private function extractFields(array $columns, mixed $item): array
    {
        if (null === $item || [] === $columns) {
            return [];
        }

        $fieldsValue = [];
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        if (!\is_array($item) && !\is_object($item)) {
            return [];
        }

        foreach ($columns as $column) {
            $parts = \explode('.', $column, 2);
            $path = $parts[1] ?? $parts[0];

            if (\is_array($item)) {
                $path = \sprintf('[%s]', $path);
            }

            $value = $propertyAccessor->getValue($item, $path);

            if ($value instanceof DateTimeInterface) {
                $value = DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.uP')
                ;
            }

            $fieldsValue[$column] = $value;
        }

        return $fieldsValue;
    }

    /** @param array<int, mixed> $results */
    private function isSingleFirstPageQuery(array $results): bool
    {
        return 1 === $this->currentPage && \count($results) < $this->maxPerPage;
    }

    private function isFetchingJoinCollection(): bool
    {
        return (bool) ($this->options[PaginatorOption::FETCH_JOIN_COLLECTION->value] ?? true);
    }

    private function isCursorPaginationEnabled(): bool
    {
        return (bool) ($this->options[PaginatorOption::ENABLE_CURSOR_PAGINATION->value] ?? false);
    }
}
