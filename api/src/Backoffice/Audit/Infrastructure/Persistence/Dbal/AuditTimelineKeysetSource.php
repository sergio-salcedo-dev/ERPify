<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Persistence\Dbal;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Query\QueryBuilder;
use Erpify\Shared\Search\Domain\Filters;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\KeysetSource;
use Override;
use UnexpectedValueException;

/**
 * The raw-DBAL side of the audit timeline's keyset walk, over the `audit_log` table, which has no Doctrine
 * entity and whose `occurred_on` is `TIMESTAMPTZ(6)` — finer than the ORM extractor's second precision, so
 * the ORM source cannot serve it:
 *
 *   - **Fixed total order** `occurred_on, id` (the `id` is the tie-break only; `occurred_on` is the forensic
 *     instant, never collapsed to `id` despite UUIDv7's temporal ordering).
 *   - **Native Postgres row-value comparison** `(occurred_on, id) <op> (:o, :i)` — SQL has tuple comparison
 *     (DQL does not), so the boundary predicate is one index-friendly expression instead of the nested
 *     OR/AND expansion the DQL predicate builder needs.
 *   - **Microsecond-precise boundary**: the cursor stores `occurred_on` at full `u` precision, so a
 *     sub-second walk never straddles a boundary.
 *   - No COUNT: the timeline never reports a total.
 *
 * A row is the scalar map the Postgres driver returns for the repository's column list, which selects no
 * binary column.
 *
 * @implements KeysetSource<array<string, scalar|null>, object>
 */
final readonly class AuditTimelineKeysetSource implements KeysetSource
{
    /** Result-set key of the primary sort column (also its cursor-value key); qualified per query. */
    private const string SORT_COLUMN = 'occurred_on';

    /** Result-set key of the tie-break column. */
    private const string TIE_BREAK_COLUMN = 'id';

    /**
     * @param QueryBuilder                          $baseQuery the repository's SELECT/FROM; never mutated
     * @param string                                $alias     the table alias of `$baseQuery`
     * @param SortDirection                         $direction the resolved direction of the `occurred_on` sort
     * @param int                                   $limit     the policy-clamped page size
     * @param Closure(array<string, mixed>): object $hydrate   maps one result row to a read model item
     */
    public function __construct(
        private QueryBuilder $baseQuery,
        private string $alias,
        private Filters $filters,
        private SearchFieldMap $filterMap,
        private AuditTimelineFilterApplier $filterApplier,
        private SortDirection $direction,
        private int $limit,
        private Closure $hydrate,
    ) {
    }

    #[Override]
    public function boundaryColumns(): array
    {
        return [self::SORT_COLUMN, self::TIE_BREAK_COLUMN];
    }

    /**
     * Strict binding of the cursor to its query: the fingerprint covers the table, the fixed sort column,
     * the resolved direction, the effective limit, the requested filters and the base query the repository
     * authored. A follow-up request that changes any of them fails fingerprint validation (422), never
     * silently mixing pages from two different queries.
     *
     * The base query enters as its SQL text, the DBAL twin of the DQL the ORM engine binds: its shape, never
     * its bound values, so a scope written as `WHERE a.resource_type = :scope` binds the cursor to that scope
     * while two values of `:scope` share a fingerprint, and an array parameter's length never shifts it. That
     * is what stops a cursor minted on one audit listing being replayed on another whose scope lives in the
     * base query rather than in the filters. The SQL is read before any filter or keyset predicate touches the
     * query, because {@see fetch()} works on a clone.
     */
    #[Override]
    public function fingerprint(): string
    {
        $filters = [];

        foreach ($this->filters as $filter) {
            $filters[] = [$filter->field, $filter->operator->value, $filter->value];
        }

        return \hash('xxh128', \json_encode([
            'table' => 'audit_log',
            'sort' => self::SORT_COLUMN,
            'direction' => $this->direction->value,
            'limit' => $this->limit,
            'filters' => $filters,
            'baseQuery' => $this->baseQuery->getSQL(),
        ], JSON_THROW_ON_ERROR));
    }

    #[Override]
    public function limit(): int
    {
        return $this->limit;
    }

    #[Override]
    public function fetch(?array $boundary, NavigationDirection $direction, int $maxResults): array
    {
        $scanDirection = NavigationDirection::Before === $direction
            ? $this->invert($this->direction)
            : $this->direction;

        $query = clone $this->baseQuery;
        $this->filterApplier->apply($query, $this->filters, $this->filterMap);
        $this->applyKeysetPredicate($query, $boundary, $scanDirection);
        $this->applyOrderBy($query, $scanDirection);
        $query->setMaxResults($maxResults);

        /** @var list<array<string, scalar|null>> */
        return $query->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, scalar|null> $row
     */
    #[Override]
    public function position(mixed $row): array
    {
        $tieBreak = $this->requireString($row[self::TIE_BREAK_COLUMN] ?? null, self::TIE_BREAK_COLUMN);

        return [
            self::SORT_COLUMN => $this->canonicalOccurredOn($row[self::SORT_COLUMN] ?? null),
            self::TIE_BREAK_COLUMN => $tieBreak,
        ];
    }

    /**
     * @param array<string, scalar|null> $row
     */
    #[Override]
    public function item(mixed $row): object
    {
        return ($this->hydrate)($row);
    }

    #[Override]
    public function count(): ?int
    {
        return null;
    }

    /**
     * @param array<string, mixed>|null $boundary
     */
    private function applyKeysetPredicate(QueryBuilder $query, ?array $boundary, SortDirection $scanDirection): void
    {
        if (null === $boundary) {
            return;
        }

        $comparison = SortDirection::ASC === $scanDirection ? '>' : '<';

        $query
            ->andWhere(\sprintf(
                '(%s.%s, %s.%s) %s (CAST(:keyset_occurred AS TIMESTAMPTZ), CAST(:keyset_id AS UUID))',
                $this->alias,
                self::SORT_COLUMN,
                $this->alias,
                self::TIE_BREAK_COLUMN,
                $comparison,
            ))
            ->setParameter(
                'keyset_occurred',
                $this->requireString($boundary[self::SORT_COLUMN] ?? null, self::SORT_COLUMN),
            )
            ->setParameter(
                'keyset_id',
                $this->requireString($boundary[self::TIE_BREAK_COLUMN] ?? null, self::TIE_BREAK_COLUMN),
            )
        ;
    }

    private function applyOrderBy(QueryBuilder $query, SortDirection $scanDirection): void
    {
        // why: the string is deliberate here, and it is the one place in the tree that still passes one.
        // This is DBAL's query builder, not the ORM's: Doctrine\DBAL\Query\QueryBuilder::orderBy()
        // declares `?string $order` and deprecates nothing, while the ORM's took PHP's SortDirection
        // in 3.7 and drops the string in 4. Handing DBAL that enum is a TypeError, not an upgrade.
        $query
            ->orderBy(\sprintf('%s.%s', $this->alias, self::SORT_COLUMN), $scanDirection->value)
            ->addOrderBy(\sprintf('%s.%s', $this->alias, self::TIE_BREAK_COLUMN), $scanDirection->value)
        ;
    }

    /**
     * Normalizes a raw `occurred_on` (the session-timezone text Postgres returns) to a canonical UTC
     * string at microsecond precision — the boundary the cursor stores and re-binds, so a page
     * fetched `before` round-trips identically to one fetched `after`. The DB value is trusted
     * internal data, so a lenient parse is acceptable here.
     */
    private function canonicalOccurredOn(mixed $raw): string
    {
        return (new DateTimeImmutable($this->requireString($raw, self::SORT_COLUMN)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.uP')
        ;
    }

    private function requireString(mixed $value, string $field): string
    {
        if (!\is_string($value)) {
            throw new UnexpectedValueException(\sprintf('Keyset column "%s" must be a string.', $field));
        }

        return $value;
    }

    private function invert(SortDirection $direction): SortDirection
    {
        return SortDirection::ASC === $direction ? SortDirection::DESC : SortDirection::ASC;
    }
}
