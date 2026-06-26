<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Persistence\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Query\QueryBuilder;
use Erpify\Shared\Search\Domain\Exception\InvalidCursor;
use Erpify\Shared\Search\Domain\Exception\UnknownSortField;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\Cursor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\CursorCodec;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\WirePaginationPolicy;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SortFieldMap;
use UnexpectedValueException;

/**
 * Keyset (cursor) paginator over the raw `audit_log` table via DBAL — the reduced, DBAL-native
 * counterpart of the ORM `DoctrineSearchEngine`, scoped to a single read model that the ORM engine
 * cannot serve (the table has no Doctrine entity, and its `occurred_on` is `TIMESTAMPTZ(6)` whereas
 * the ORM cursor extractor normalizes to second precision).
 *
 * It reuses the shared keyset KERNEL — {@see CursorCodec} (HMAC signing), {@see Cursor} and the
 * {@see WirePaginationPolicy} limits — and the wire/domain layer, but owns the orchestration:
 *
 *   - **Fixed total order** `occurred_on, id` (the `id` is the tie-break only; `occurred_on` is the
 *     forensic instant, never collapsed to `id` despite UUIDv7's temporal ordering).
 *   - **Native Postgres row-value comparison** `(occurred_on, id) <op> (:o, :i)` — SQL has tuple
 *     comparison (DQL does not), so the boundary predicate is a single index-friendly expression
 *     instead of the nested OR/AND expansion the DQL `KeysetPredicateBuilder` needs.
 *   - **Microsecond-precise boundary**: the cursor stores `occurred_on` at full `u` precision, so a
 *     sub-second walk never straddles a boundary.
 *   - `before` is contained here (invert the scan + re-reverse in memory); the page contract never
 *     sees it. COUNT and the synthetic "go-to-date" cursor are intentionally omitted (not needed).
 *
 * Deuda D (registrada): cuando mantener este orquestador y el ORM empiece a costar, extraer el
 * algoritmo a un núcleo agnóstico de persistencia con un puerto fetch/position.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class AuditTimelineKeysetPaginator
{
    /** Result-set key of the primary sort column (also its cursor-value key); qualified per query. */
    private const string SORT_COLUMN = 'occurred_on';

    /** Result-set key of the tie-break column. */
    private const string TIE_BREAK_COLUMN = 'id';

    public function __construct(
        private AuditTimelineFilterApplier $filterApplier,
        private CursorCodec $cursorCodec,
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
        $direction = $this->resolveDirection($criteria, $sortMap);
        $limit = $this->resolveLimit($criteria);
        $fingerprint = $this->fingerprint($criteria, $direction);
        $cursor = $this->decodeCursor($criteria->cursor, $fingerprint, $criteria->routingDirection);

        $isBefore = NavigationDirection::Before === $criteria->routingDirection;
        $scanDirection = $isBefore ? $this->invert($direction) : $direction;

        $this->filterApplier->apply($baseQuery, $criteria->filters, $filterMap);
        $this->applyKeysetPredicate($baseQuery, $alias, $cursor, $scanDirection);
        $this->applyOrderBy($baseQuery, $alias, $scanDirection);
        $baseQuery->setMaxResults($limit + 1);

        /** @var list<array<string, mixed>> $rows */
        $rows = $baseQuery->executeQuery()->fetchAllAssociative();

        $hasExtra = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        // `before` was fetched in inverted physical order — restore the caller-facing order.
        $rows = $isBefore ? \array_reverse($rows) : $rows;

        /** @var list<object> $items */
        $items = \array_map($hydrate, $rows);

        return $this->buildPage($items, $rows, $hasExtra, $cursor, $isBefore, $fingerprint);
    }

    /**
     * @param list<object>               $items
     * @param list<array<string, mixed>> $rows
     *
     * @return Page<object>
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function buildPage(
        array $items,
        array $rows,
        bool $hasExtra,
        ?Cursor $cursor,
        bool $isBefore,
        string $fingerprint,
    ): Page {
        $hadCursor = $cursor instanceof Cursor;

        if ([] === $rows) {
            if (!$hadCursor) {
                return new Page([], false, false);
            }

            // Reached via a cursor walking into a gap/edge: keep the directional affordance of a
            // populated page, re-signing the inbound boundary under the opposite direction so the
            // recovery cursor walks back toward where you came.
            return new Page(
                [],
                $isBefore,
                !$isBefore,
                null,
                $isBefore ? $this->reencodeCursor($cursor, Cursor::DIRECTION_AFTER, $fingerprint) : null,
                $isBefore ? null : $this->reencodeCursor($cursor, Cursor::DIRECTION_BEFORE, $fingerprint),
            );
        }

        $nextCursor = $this->encodeBoundary(\array_last($rows), Cursor::DIRECTION_AFTER, $fingerprint);
        $prevCursor = $this->encodeBoundary($rows[0], Cursor::DIRECTION_BEFORE, $fingerprint);

        return new Page(
            $items,
            $isBefore ? $hadCursor : $hasExtra,
            $isBefore ? $hasExtra : $hadCursor,
            null,
            $nextCursor,
            $prevCursor,
        );
    }

    private function applyKeysetPredicate(
        QueryBuilder $queryBuilder,
        string $alias,
        ?Cursor $cursor,
        SortDirection $scanDirection,
    ): void {
        if (!$cursor instanceof Cursor) {
            return;
        }

        $comparison = SortDirection::ASC === $scanDirection ? '>' : '<';

        $queryBuilder
            ->andWhere(\sprintf(
                '(%s.%s, %s.%s) %s (CAST(:keyset_occurred AS TIMESTAMPTZ), CAST(:keyset_id AS UUID))',
                $alias,
                self::SORT_COLUMN,
                $alias,
                self::TIE_BREAK_COLUMN,
                $comparison,
            ))
            ->setParameter('keyset_occurred', $this->cursorValue($cursor, self::SORT_COLUMN))
            ->setParameter('keyset_id', $this->cursorValue($cursor, self::TIE_BREAK_COLUMN))
        ;
    }

    private function applyOrderBy(QueryBuilder $queryBuilder, string $alias, SortDirection $scanDirection): void
    {
        $queryBuilder
            ->orderBy(\sprintf('%s.%s', $alias, self::SORT_COLUMN), $scanDirection->value)
            ->addOrderBy(\sprintf('%s.%s', $alias, self::TIE_BREAK_COLUMN), $scanDirection->value)
        ;
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

    private function resolveLimit(SearchCriteria $criteria): int
    {
        return \max(1, \min($criteria->limit ?? WirePaginationPolicy::DEFAULT_LIMIT, WirePaginationPolicy::MAX_LIMIT));
    }

    private function decodeCursor(
        ?string $encoded,
        string $fingerprint,
        NavigationDirection $routingDirection,
    ): ?Cursor {
        if (null === $encoded || '' === $encoded) {
            return null;
        }

        $cursor = $this->cursorCodec->decode($encoded, $fingerprint, $this->directionToken($routingDirection));

        // Arity guard (the codec validates integrity, not shape): a cursor missing an order-by key
        // would make the predicate bind a null and match nothing — surface it as 422, never a 500.
        foreach ([self::SORT_COLUMN, self::TIE_BREAK_COLUMN] as $column) {
            if (!\array_key_exists($column, $cursor->values)) {
                throw InvalidCursor::payload();
            }
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function encodeBoundary(array $row, string $direction, string $fingerprint): string
    {
        $tieBreak = $this->requireString($row[self::TIE_BREAK_COLUMN] ?? null, self::TIE_BREAK_COLUMN);
        $values = [
            self::SORT_COLUMN => $this->canonicalOccurredOn($row[self::SORT_COLUMN] ?? null),
            self::TIE_BREAK_COLUMN => $tieBreak,
        ];

        return $this->cursorCodec->encode(new Cursor(CursorCodec::CURRENT_VERSION, $direction, $values, $fingerprint));
    }

    private function reencodeCursor(Cursor $cursor, string $direction, string $fingerprint): string
    {
        return $this->cursorCodec->encode(
            new Cursor(CursorCodec::CURRENT_VERSION, $direction, $cursor->values, $fingerprint),
        );
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

    private function cursorValue(Cursor $cursor, string $column): string
    {
        return $this->requireString($cursor->values[$column] ?? null, $column);
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

    private function directionToken(NavigationDirection $direction): string
    {
        return NavigationDirection::Before === $direction ? Cursor::DIRECTION_BEFORE : Cursor::DIRECTION_AFTER;
    }

    /**
     * Strict binding of the cursor to its query: the fingerprint covers the table, the fixed sort
     * column, the resolved direction, the effective limit and the applied filters. A follow-up
     * request that changes any of them fails fingerprint validation (422), never silently mixing
     * pages from two different queries.
     */
    private function fingerprint(SearchCriteria $criteria, SortDirection $direction): string
    {
        $filters = [];

        foreach ($criteria->filters as $filter) {
            $filters[] = [$filter->field, $filter->operator->value, $filter->value];
        }

        return \hash('xxh128', \json_encode([
            'table' => 'audit_log',
            'sort' => self::SORT_COLUMN,
            'direction' => $direction->value,
            'limit' => $this->resolveLimit($criteria),
            'filters' => $filters,
        ], JSON_THROW_ON_ERROR));
    }
}
