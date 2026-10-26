<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Persistence\Dbal;

use Doctrine\DBAL\Query\QueryBuilder;
use Erpify\Shared\Search\Domain\Exception\UnknownSortField;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SortFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\KeysetPaginator;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\WirePaginationPolicy;

/**
 * Keyset (cursor) pagination over the raw `audit_log` table: it settles the request's sort direction and page
 * size, then hands the walk to the shared {@see KeysetPaginator} through an {@see AuditTimelineKeysetSource} —
 * the DBAL counterpart of the ORM `DoctrineSearchEngine`. Ordering, the keyset predicate, the limit and cursor
 * encoding stay out of the repository. COUNT and the synthetic "go-to-date" cursor are not offered here.
 */
final readonly class AuditTimelineKeysetPaginator
{
    public function __construct(
        private AuditTimelineFilterApplier $filterApplier,
        private KeysetPaginator $paginator,
    ) {
    }

    /**
     * @param string                                 $alias   the table alias of `$baseQuery`
     * @param callable(array<string, mixed>): object $hydrate maps one result row to a read model item
     *
     * @return Page<object>
     */
    public function paginate(
        QueryBuilder $baseQuery,
        string $alias,
        SearchCriteria $criteria,
        SearchFieldMap $filterMap,
        SortFieldMap $sortMap,
        callable $hydrate,
    ): Page {
        $source = new AuditTimelineKeysetSource(
            $baseQuery,
            $alias,
            $criteria->filters,
            $filterMap,
            $this->filterApplier,
            $this->resolveDirection($criteria, $sortMap),
            WirePaginationPolicy::wire()->limitFor($criteria->limit),
            $hydrate(...),
        );

        return $this->paginator->paginate($source, $criteria->cursor, $criteria->routingDirection);
    }

    /**
     * The only sortable field is `occurredOn`; any other `sort` is a 422 `unknown-sort-field` rather
     * than a silently ignored value. The default is `occurred_on DESC` — a forensic timeline reads
     * newest-first.
     */
    private function resolveDirection(SearchCriteria $criteria, SortFieldMap $sortMap): SortDirection
    {
        if (null !== $criteria->sort && '' !== $criteria->sort && null === $sortMap->pathFor($criteria->sort)) {
            throw UnknownSortField::named($criteria->sort);
        }

        return $criteria->direction ?? SortDirection::DESC;
    }
}
