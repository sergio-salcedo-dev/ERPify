<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search;

use Doctrine\ORM\QueryBuilder;
use Erpify\Shared\Domain\Search\Exception\InvalidCursor;
use Erpify\Shared\Domain\Search\Exception\UnknownSortField;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\PaginationMode;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Domain\Search\SortDirection;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\AppliedLimit;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\AppliedSort;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\Cursor;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\CursorCodec;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\CursorPositionExtractor;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\FingerprintCanonicalizer;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\KeysetPredicateBuilder;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\OrderByColumns;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\QueryExecutionTrace;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\WirePaginationPolicy;
use LogicException;

/**
 * The single query-shaper of the keyset read-path: given a repository-authored base
 * {@see QueryBuilder} (its `SELECT`/`FROM`/joins) plus the search {@see SearchCriteria}, it runs
 * the fixed 8-step pipeline (ADR Process Patterns) and returns an immutable domain {@see Page}.
 * It is the ONLY collaborator that touches Doctrine query mechanics (NFR4); the kernel
 * collaborators it composes ({@see FilterApplier}, {@see CursorCodec}, {@see KeysetPredicateBuilder},
 * {@see FingerprintCanonicalizer}, {@see CursorPositionExtractor}) are pure.
 *
 * Pipeline (invariants enforced up to step 6 only; nothing after is part of the guarantees, AR10):
 *   1. resolve sort + `id` tie-break          → {@see AppliedSort} (semantic) + {@see OrderByColumns} (physical)
 *   2. apply filters                           → {@see Keyset\AppliedFilters} receipt
 *   3. clamp limit by the policy               → {@see AppliedLimit}
 *   4. SEAL the {@see QueryExecutionTrace} + fingerprint  ← Row Uniqueness Contract guard runs HERE
 *   5. validate the cursor (intrinsic→extrinsic, then arity)
 *   6. build the keyset predicate
 *   7. execute with the +1 trick (`before`: invert the ORDER BY in SQL, re-reverse in memory)
 *   8. build the immutable {@see Page} + encode both cursors
 *
 * The fingerprint derives EXCLUSIVELY from the sealed trace (step 4), never from raw request input,
 * and no validation output mutates the trace post-sealing (AR22).
 *
 * This engine is the single runtime query-shaper for the filterable-search read-path: the HTTP
 * layer hands it a base query builder plus allow-lists and receives an immutable {@see Page} with
 * opaque cursors (after/before envelope, 422 on an invalid cursor). It is also verified in
 * isolation by DIRECT tests (property, snapshot, contract — never HTTP).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
final readonly class DoctrineSearchEngine
{
    /**
     * Boundary floor for the `id` tie-break of a synthetic position: the nil UUID collates before
     * every real id, so with the ASC tie-break and the wire's exclusive boundary, `id > floor`
     * admits all rows tied on the primary sort key — the synthesized position is INCLUSIVE of the
     * target value.
     */
    private const string SYNTHETIC_TIE_BREAK_FLOOR = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private FilterApplier $filterApplier,
        private CursorCodec $cursorCodec,
        private FingerprintCanonicalizer $fingerprintCanonicalizer,
        private KeysetPredicateBuilder $predicateBuilder,
        private CursorPositionExtractor $positionExtractor,
        private RowUniquenessGuard $rowUniquenessGuard,
    ) {
    }

    /**
     * Runs the keyset pipeline against the repository's base query builder and returns one page.
     *
     * @param QueryBuilder $queryBuilder     base query builder: `SELECT`/`FROM`/joins only, never
     *                                       order/limit/where-cursor (those are the engine's monopoly)
     * @param string       $routingDirection {@see Cursor::DIRECTION_AFTER} or `DIRECTION_BEFORE` — the
     *                                       single routing authority; the cursor's own `dir` is an
     *                                       integrity binding, never consulted for navigation (AR21)
     *
     * @return Page<object>
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function paginate(
        QueryBuilder $queryBuilder,
        SearchCriteria $criteria,
        SearchFieldMap $searchFieldMap,
        SortFieldMap $sortFieldMap,
        PaginatorConfig $config,
        WirePaginationPolicy $policy,
        string $routingDirection = Cursor::DIRECTION_AFTER,
    ): Page {
        $alias = $this->rootAlias($queryBuilder);

        // Steps 1–3: the three receipts of everything that shapes ordering and selection.
        [$appliedSort, $orderByColumns] = $this->resolveSort($criteria, $sortFieldMap);
        $appliedFilters = $this->filterApplier->apply($queryBuilder, $criteria->filters, $searchFieldMap);
        $appliedLimit = $this->resolveLimit($criteria, $policy);

        // Step 4: seal the trace, derive the fingerprint, and guard the Row Uniqueness Contract.
        $entity = $this->rootEntity($queryBuilder);
        $trace = new QueryExecutionTrace($entity, $appliedFilters, $appliedSort, $appliedLimit);
        $fingerprint = $this->fingerprintCanonicalizer->fingerprint($trace);
        $this->rowUniquenessGuard->assert($queryBuilder);

        // Step 5: validate the cursor (a malformed one is an InvalidCursor, never a 500).
        $cursor = $this->decodeCursor($criteria->cursor, $fingerprint, $routingDirection, $orderByColumns);

        // Steps 6–8: predicate, execute with the +1 trick, build the page.
        return $this->execute(
            $queryBuilder,
            $alias,
            $orderByColumns,
            $cursor,
            $appliedLimit->value,
            $routingDirection,
            $policy,
            $config,
            $fingerprint,
        );
    }

    /**
     * The "go-to-date" seam (K14): synthesizes an `after` cursor positioned AT a primary sort-key
     * value with no boundary row to extract from — the keyset model's inherent ability to jump to
     * a key value instead of walking pages. No new endpoint: the token flows through the same
     * `after` wire param as any real cursor.
     *
     * The token reuses the exact emission machinery of a real cursor (extractor normalization at
     * column precision, codec signing, fingerprint of the sealed trace), so it is
     * wire-indistinguishable from one minted off a row and binds to the query it was synthesized
     * for: a follow-up request must carry the same filters, sort, direction and limit, or
     * fingerprint validation rejects it (422 `invalid-cursor`).
     *
     * Landing semantics: under an ASC primary sort the page starts at the first row whose key is
     * `>=` the target; under DESC, `<=` (see {@see self::SYNTHETIC_TIE_BREAK_FLOOR} for why ties
     * on the target are included). Affordance is conservative by construction (K10): paginating
     * with the token is a cursor-bearing request, so the landing page reports `hasPrev: true`
     * even when it is the dataset's very first row.
     *
     * The Row Uniqueness guard is not run here — no rows are fetched; the paired {@see paginate}
     * call asserts it against the same base builder.
     *
     * @param QueryBuilder $queryBuilder the same base builder contract as {@see paginate} —
     *                                   `SELECT`/`FROM`/joins only; it is never mutated here
     * @param mixed        $sortKeyValue a TRUSTED, typed value in the physical sort column's
     *                                   domain: a `DateTimeInterface` for temporal columns
     *                                   (normalized to UTC at column precision like any real
     *                                   boundary), a pre-normalized scalar for normalized text
     *                                   columns (no field normalizer runs here). Validating and
     *                                   coercing untrusted wire input into that domain is the
     *                                   calling adapter's obligation, as {@see FilterApplier}
     *                                   does for filter values.
     */
    public function synthesizeCursor(
        QueryBuilder $queryBuilder,
        SearchCriteria $criteria,
        SearchFieldMap $searchFieldMap,
        SortFieldMap $sortFieldMap,
        WirePaginationPolicy $policy,
        mixed $sortKeyValue,
    ): string {
        if (null === $criteria->sort || '' === $criteria->sort) {
            throw new LogicException(
                'Synthesizing a cursor position requires a primary sort: natural id order has no semantic sort key.',
            );
        }

        [$appliedSort, $orderByColumns] = $this->resolveSort($criteria, $sortFieldMap);

        if (OrderByColumns::TIE_BREAK_COLUMN === $this->fieldOf($orderByColumns->columnNames()[0])) {
            throw new LogicException(
                'Cannot synthesize a cursor position over the id tie-break: the target value would replace the '
                . 'inclusive floor and the landing would silently turn exclusive.',
            );
        }

        // Filters run against a clone: only the receipt matters here, and the caller's base
        // builder must stay pristine for the paginate() call the token will be used with.
        $appliedFilters = $this->filterApplier->apply(clone $queryBuilder, $criteria->filters, $searchFieldMap);
        $appliedLimit = $this->resolveLimit($criteria, $policy);

        $fingerprint = $this->fingerprintCanonicalizer->fingerprint(
            new QueryExecutionTrace($this->rootEntity($queryBuilder), $appliedFilters, $appliedSort, $appliedLimit),
        );

        $values = $this->positionExtractor->extract(
            $orderByColumns,
            $this->syntheticBoundaryRow($orderByColumns, $sortKeyValue),
        );

        // A null boundary value would be signed into a token whose predicate matches nothing
        // (SQL three-valued logic) — every landing an empty page, indistinguishable from a gap.
        // Fail loud instead: it means a null target, or an order-by column the synthetic row
        // does not cover.
        if (\in_array(null, $values, true)) {
            throw new LogicException(
                'A synthetic cursor position must cover every order-by column with a non-null boundary value.',
            );
        }

        return $this->cursorCodec->encode(
            new Cursor(CursorCodec::CURRENT_VERSION, Cursor::DIRECTION_AFTER, $values, $fingerprint),
        );
    }

    /**
     * The array row the extractor reads a synthetic position from: the target value on the primary
     * sort key, the nil-UUID floor on the `id` tie-break.
     *
     * @return array<string, mixed>
     */
    private function syntheticBoundaryRow(OrderByColumns $orderByColumns, mixed $sortKeyValue): array
    {
        return [
            $this->fieldOf($orderByColumns->tieBreakColumn()) => self::SYNTHETIC_TIE_BREAK_FLOOR,
            $this->fieldOf($orderByColumns->columnNames()[0]) => $sortKeyValue,
        ];
    }

    /** Strips the optional `alias.` of a DQL identifier, mirroring the extractor's property path. */
    private function fieldOf(string $column): string
    {
        $parts = \explode('.', $column, 2);

        return $parts[1] ?? $parts[0];
    }

    /**
     * Step 1 — resolve the public sort field to its physical column list. {@see AppliedSort} keeps
     * the PUBLIC field for the canonical chain; {@see OrderByColumns} keeps the physical column path
     * plus the `id` tie-break for the predicate. A blank/absent sort degrades to the tie-break alone.
     *
     * @return array{AppliedSort, OrderByColumns}
     */
    private function resolveSort(SearchCriteria $criteria, SortFieldMap $sortFieldMap): array
    {
        $direction = $criteria->direction ?? SortDirection::ASC;

        if (null === $criteria->sort || '' === $criteria->sort) {
            return [new AppliedSort(OrderByColumns::TIE_BREAK_COLUMN, $direction), OrderByColumns::tieBreakOnly()];
        }

        $path = $sortFieldMap->pathFor($criteria->sort)
            ?? throw UnknownSortField::named($criteria->sort);

        return [
            new AppliedSort($criteria->sort, $direction),
            OrderByColumns::fromPrimarySort($path, $direction),
        ];
    }

    /**
     * Step 3 — resolve the effective page size and clamp it to the policy ceiling; the receipt is
     * always ≥ 1. A `null` criteria limit is "unspecified" and falls back to the policy's
     * `defaultLimit`, so an adapter picks its default by selecting the policy — never by baking a
     * value into {@see SearchCriteria}.
     */
    private function resolveLimit(SearchCriteria $criteria, WirePaginationPolicy $policy): AppliedLimit
    {
        return new AppliedLimit(\max(1, \min($criteria->limit ?? $policy->defaultLimit, $policy->maxLimit)));
    }

    /**
     * Step 5 — decode and validate the cursor, or return `null` for a first-page request. The codec
     * enforces signature → version → payload → fingerprint; this method adds the ARITY guard (OQ-2):
     * a cursor whose values do not cover exactly the order-by columns would make the predicate builder
     * raise an {@see \InvalidArgumentException} (a 500), so it is mapped to {@see InvalidCursor::payload()}
     * (the 422 family) BEFORE the builder ever runs — the codec validates integrity, not arity.
     */
    private function decodeCursor(
        ?string $encoded,
        string $fingerprint,
        string $routingDirection,
        OrderByColumns $orderByColumns,
    ): ?Cursor {
        if (null === $encoded || '' === $encoded) {
            return null;
        }

        $cursor = $this->cursorCodec->decode($encoded, $fingerprint, $routingDirection);

        foreach ($orderByColumns->columnNames() as $column) {
            if (!\array_key_exists($column, $cursor->values)) {
                throw InvalidCursor::payload();
            }
        }

        return $cursor;
    }

    /**
     * Steps 6–8 — build the predicate (when a cursor is present), execute with the +1 trick, and
     * assemble the immutable page. `before` is contained entirely here (FR13/K15): the ORDER BY is
     * inverted in SQL and the page is re-reversed in memory, so the contract never sees it.
     *
     * @return Page<object>
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    private function execute(
        QueryBuilder $queryBuilder,
        string $alias,
        OrderByColumns $orderByColumns,
        ?Cursor $cursor,
        int $limit,
        string $routingDirection,
        WirePaginationPolicy $policy,
        PaginatorConfig $config,
        string $fingerprint,
    ): Page {
        $isBefore = Cursor::DIRECTION_BEFORE === $routingDirection;
        $physicalColumns = $isBefore ? $this->invert($orderByColumns) : $orderByColumns;

        $fetch = clone $queryBuilder;
        $this->applyKeysetPredicate($fetch, $physicalColumns, $cursor, $alias, $policy);
        $this->applyOrderBy($fetch, $physicalColumns, $alias);
        $fetch->setMaxResults($limit + 1);

        /** @var list<object> $rows */
        $rows = $fetch->getQuery()->getResult();

        $hasExtra = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        // `before` fetched in reversed physical order — restore the caller-facing order.
        $items = $isBefore ? \array_reverse($rows) : $rows;

        return $this->buildPage(
            $items,
            $orderByColumns,
            $hasExtra,
            $cursor,
            $isBefore,
            $fingerprint,
            $this->countIfDetailed($queryBuilder, $alias, $config),
        );
    }

    /**
     * Step 6 — bind the keyset boundary predicate. The builder qualifies every bare column (e.g. the
     * `id` tie-break) with the root alias as it emits, so the DQL is bound here as-is.
     */
    private function applyKeysetPredicate(
        QueryBuilder $queryBuilder,
        OrderByColumns $columns,
        ?Cursor $cursor,
        string $alias,
        WirePaginationPolicy $policy,
    ): void {
        if (!$cursor instanceof Cursor) {
            return;
        }

        $predicate = $this->predicateBuilder->build($columns, $cursor->values, $policy, $alias);

        $queryBuilder->andWhere($predicate['dql']);

        foreach ($predicate['parameters'] as $name => $value) {
            $queryBuilder->setParameter($name, $value);
        }
    }

    private function applyOrderBy(QueryBuilder $queryBuilder, OrderByColumns $columns, string $alias): void
    {
        // why: ordering is the engine's monopoly (the base QB contract is SELECT/FROM/joins only). A
        // stray ORDER BY from the repo would, via addOrderBy, sit AHEAD of the keyset columns and become
        // the primary sort — silently breaking the monotonic walk. Reset it first, as countIfDetailed does.
        $queryBuilder->resetDQLPart('orderBy');

        foreach ($columns->all() as $column) {
            $queryBuilder->addOrderBy($this->qualifyColumn($column['column'], $alias), $column['direction']->value);
        }
    }

    /**
     * Step 8 — the immutable page plus both opaque cursors. The cursor values are extracted at COLUMN
     * precision from the boundary rows (last → next, first → prev) and keyed by the ORIGINAL column
     * names, so a page fetched `before` round-trips identically to one fetched `after`.
     *
     * @param list<object> $items
     *
     * @return Page<object>
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function buildPage(
        array $items,
        OrderByColumns $orderByColumns,
        bool $hasExtra,
        ?Cursor $cursor,
        bool $isBefore,
        string $fingerprint,
        ?int $count,
    ): Page {
        $hadCursor = $cursor instanceof Cursor;

        if ([] === $items) {
            if (!$cursor instanceof Cursor) {
                // First-page empty: a genuinely empty dataset, no affordance in either direction.
                return new Page([], false, false, $count);
            }

            // Reached via a cursor. The affordance derives from the SAME directional formula as a
            // populated page (hasExtra = false): a `before` walk into a gap/edge keeps hasNext (the
            // page you came from, ahead, is recoverable via next); an `after` walk keeps hasPrev.
            // NOT a special bidirectional state (empty-before ⇒ hasNext=true,hasPrev=false;
            // empty-after ⇒ hasNext=false,hasPrev=true). W10 (Linkability): the true flag must carry
            // a usable cursor, so the recovery cursor re-signs the INBOUND cursor's own boundary
            // values under the OPPOSITE direction — navigating it walks back toward where you came.
            return new Page(
                [],
                $isBefore,
                !$isBefore,
                $count,
                $isBefore ? $this->reencodeCursor($cursor, Cursor::DIRECTION_AFTER, $fingerprint) : null,
                $isBefore ? null : $this->reencodeCursor($cursor, Cursor::DIRECTION_BEFORE, $fingerprint),
            );
        }

        $lastItem = \array_last($items);
        $nextCursor = $this->encodeBoundary($orderByColumns, $lastItem, Cursor::DIRECTION_AFTER, $fingerprint);
        $prevCursor = $this->encodeBoundary($orderByColumns, $items[0], Cursor::DIRECTION_BEFORE, $fingerprint);

        // `before` discovers "more rows exist before" via its extra row; `after` discovers "more after".
        $hasNext = $isBefore ? $hadCursor : $hasExtra;
        $hasPrev = $isBefore ? $hasExtra : $hadCursor;

        return new Page($items, $hasNext, $hasPrev, $count, $nextCursor, $prevCursor);
    }

    /**
     * Re-signs an existing cursor's boundary values under a (possibly flipped) direction. Used for
     * the empty-page recovery cursor (W10): the inbound cursor's values become a usable token for
     * the opposite navigation, so a true hasNext/hasPrev flag is never stranded without a cursor.
     * The boundary is exclusive, so recovery lands just past the boundary row (documented edge of
     * gap navigation).
     */
    private function reencodeCursor(Cursor $cursor, string $direction, string $fingerprint): string
    {
        return $this->cursorCodec->encode(
            new Cursor(CursorCodec::CURRENT_VERSION, $direction, $cursor->values, $fingerprint),
        );
    }

    private function encodeBoundary(
        OrderByColumns $orderByColumns,
        object $row,
        string $direction,
        string $fingerprint,
    ): string {
        $values = $this->positionExtractor->extract($orderByColumns, $row);

        return $this->cursorCodec->encode(new Cursor(CursorCodec::CURRENT_VERSION, $direction, $values, $fingerprint));
    }

    /** Detailed mode runs one COUNT over the filtered query (no order/limit/predicate); light skips it. */
    private function countIfDetailed(QueryBuilder $queryBuilder, string $alias, PaginatorConfig $config): ?int
    {
        if (PaginationMode::DETAILED !== $config->paginationMode) {
            return null;
        }

        $identifier = $queryBuilder->getEntityManager()
            ->getClassMetadata($this->rootEntity($queryBuilder))
            ->getSingleIdentifierFieldName()
        ;

        $countQuery = (clone $queryBuilder)
            ->select(\sprintf('COUNT(%s.%s)', $alias, $identifier))
            ->resetDQLPart('orderBy')
            ->setFirstResult(null)
            ->setMaxResults(null)
            ->getQuery()
        ;

        return (int) $countQuery->getSingleScalarResult();
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

    private function qualifyColumn(string $column, string $alias): string
    {
        return \str_contains($column, '.') ? $column : $alias . '.' . $column;
    }

    private function rootAlias(QueryBuilder $queryBuilder): string
    {
        $aliases = $queryBuilder->getRootAliases();

        if ([] === $aliases) {
            throw new LogicException('The keyset engine requires a query builder with at least one root alias.');
        }

        return $aliases[0];
    }

    /**
     * The root entity FQCN — the fully-qualified class name is the trace's entity segment (a globally
     * unique identity, so two homonymous entities across bounded contexts never collide in the
     * fingerprint canonical chain) and {@see countIfDetailed}'s `getClassMetadata` lookup key.
     *
     * @return class-string
     */
    private function rootEntity(QueryBuilder $queryBuilder): string
    {
        $entities = $queryBuilder->getRootEntities();

        if ([] === $entities) {
            throw new LogicException('The keyset engine requires a query builder with at least one root entity.');
        }

        return $entities[0];
    }
}
