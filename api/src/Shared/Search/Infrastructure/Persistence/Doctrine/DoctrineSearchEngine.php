<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\QueryBuilder;
use Erpify\Shared\Search\Domain\Exception\UnknownSortField;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\AppliedLimit;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\AppliedSort;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\CursorPositionExtractor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\FingerprintCanonicalizer;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\KeysetPredicateBuilder;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\OrderByColumns;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\QueryExecutionTrace;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\Cursor;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\CursorCodec;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\KeysetPaginator;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\WirePaginationPolicy;
use LogicException;

/**
 * The ORM entry point of the keyset read-path: given a repository-authored base
 * {@see QueryBuilder} (its `SELECT`/`FROM`/joins) plus the search {@see SearchCriteria}, it settles
 * everything that shapes the query and the cursor's binding, then hands the walk itself to the
 * persistence-agnostic {@see KeysetPaginator} through a {@see DoctrineKeysetSource}, and returns an
 * immutable domain {@see Page}. The kernel collaborators it composes ({@see FilterApplier},
 * {@see CursorCodec}, {@see KeysetPredicateBuilder}, {@see FingerprintCanonicalizer},
 * {@see CursorPositionExtractor}) are pure; Doctrine query mechanics live here and in the source.
 *
 * Pipeline (invariants enforced up to step 6 only; nothing after is part of the guarantees, AR10):
 *   1. resolve sort + `id` tie-break          → {@see AppliedSort} (semantic) + {@see OrderByColumns} (physical)
 *   2. apply filters                           → {@see Keyset\AppliedFilters} receipt
 *   3. clamp limit by the policy               → {@see AppliedLimit}
 *   4. SEAL the {@see QueryExecutionTrace} + fingerprint  ← Row Uniqueness Contract guard runs HERE
 *   5. validate the cursor (intrinsic→extrinsic, then arity)            ┐
 *   6. build the keyset predicate                                        │ {@see KeysetPaginator}
 *   7. execute with the +1 trick (`before`: invert the ORDER BY in SQL)  │ over the
 *   8. build the immutable {@see Page} + encode both cursors             ┘ {@see DoctrineKeysetSource}
 *
 * The fingerprint derives EXCLUSIVELY from the sealed trace (step 4), never from raw request input,
 * and no validation output mutates the trace post-sealing (AR22).
 *
 * This engine is the single runtime query-shaper for the ORM filterable-search read-path: the HTTP
 * layer hands it a base query builder plus allow-lists and receives an immutable {@see Page} with
 * opaque cursors (after/before envelope, 422 on an invalid cursor). It is also verified in
 * isolation by DIRECT tests (property, snapshot, contract — never HTTP).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
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
        private KeysetPaginator $paginator,
    ) {
    }

    /**
     * Runs the keyset pipeline against the repository's base query builder and returns one page.
     *
     * @param QueryBuilder        $queryBuilder     base query builder: `SELECT`/`FROM`/joins only, never
     *                                              order/limit/where-cursor (those are the engine's monopoly)
     * @param NavigationDirection $routingDirection the single routing authority; the cursor's own `dir` is
     *                                              an integrity binding, never consulted for navigation
     *
     * @return Page<object>
     */
    public function paginate(
        QueryBuilder $queryBuilder,
        SearchCriteria $criteria,
        SearchFieldMap $searchFieldMap,
        SortFieldMap $sortFieldMap,
        PaginatorConfig $config,
        WirePaginationPolicy $policy,
        NavigationDirection $routingDirection = NavigationDirection::After,
    ): Page {
        $alias = $this->rootAlias($queryBuilder);

        // Captured BEFORE the filter applier mutates the builder, so it holds only the repository's
        // base query (SELECT/FROM/JOIN/base-WHERE), never the request filters (those are AppliedFilters).
        $baseQuery = $this->baseQueryIdentity($queryBuilder);

        // Steps 1–3: the three receipts of everything that shapes ordering and selection.
        [$appliedSort, $orderByColumns] = $this->resolveSort($criteria, $sortFieldMap);
        $appliedFilters = $this->filterApplier->apply($queryBuilder, $criteria->filters, $searchFieldMap);
        $appliedLimit = $this->resolveLimit($criteria, $policy);

        // Step 4: seal the trace, derive the fingerprint, and guard the Row Uniqueness Contract.
        $entity = $this->rootEntity($queryBuilder);
        $trace = new QueryExecutionTrace($entity, $appliedFilters, $appliedSort, $appliedLimit, $baseQuery);
        $fingerprint = $this->fingerprintCanonicalizer->fingerprint($trace);
        $this->rowUniquenessGuard->assert($queryBuilder);

        // Steps 5–8: the walk itself (a malformed cursor is an InvalidCursor, never a 500).
        return $this->paginator->paginate(
            new DoctrineKeysetSource(
                $queryBuilder,
                $alias,
                $entity,
                $orderByColumns,
                $fingerprint,
                $appliedLimit->value,
                $policy,
                $config->paginationMode,
                $this->predicateBuilder,
                $this->positionExtractor,
            ),
            $criteria->cursor,
            $routingDirection,
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

        // Same base-query identity as paginate(): here the builder is pristine (filters ran on a
        // clone), so it is read directly. The synthetic cursor must validate on the paginate() call
        // it accompanies, so both trace-construction sites must derive it in lockstep.
        $fingerprint = $this->fingerprintCanonicalizer->fingerprint(
            new QueryExecutionTrace(
                $this->rootEntity($queryBuilder),
                $appliedFilters,
                $appliedSort,
                $appliedLimit,
                $this->baseQueryIdentity($queryBuilder),
            ),
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

    /** Step 3 — the policy's page size for this request, as the receipt the trace seals. */
    private function resolveLimit(SearchCriteria $criteria, WirePaginationPolicy $policy): AppliedLimit
    {
        return new AppliedLimit($policy->limitFor($criteria->limit));
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
     * fingerprint canonical chain) and the COUNT's `getClassMetadata` lookup key.
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

    /**
     * The base query's structural identity — the DQL the repository authored (SELECT/FROM/JOIN/base
     * WHERE), read while the builder still holds only that base. Sealed into the {@see QueryExecutionTrace}
     * so two routes over the same aggregate root with divergent base predicates (the account collection
     * vs a bank's nested accounts) mint distinct fingerprints; a cursor minted on one then fails
     * fingerprint validation on the other (422 `invalid-cursor`) instead of paginating a foreign scope.
     * It is the whole base DQL rather than only WHERE/JOIN because it is deterministic per route,
     * type-clean (a `string`, unlike the mixed DQL parts), and a superset can only be a stricter binding.
     * Structural, not value-bound: the `:bankId` placeholder — not its value — is what the two nested
     * requests share, so a cursor binds to the route's scope shape, not to one bank.
     *
     * Precondition (unguarded, holds for every current consumer): the base query's DQL must be
     * BYTE-STABLE across the mint request and every follow-up — a fixed-shape base builder with NAMED
     * placeholders. A base predicate carrying a Doctrine AUTO-generated parameter name (`?1`,
     * `expr()->in()`), or a JOIN/WHERE added conditionally on request state, would shift the DQL between
     * pages and reject a legitimate cursor (spurious 422). The binding is likewise coupled to Doctrine's
     * DQL string generation: a library upgrade that alters it re-mints every fingerprint at once
     * (bounded — cursors are ephemeral, a retry re-mints).
     */
    private function baseQueryIdentity(QueryBuilder $queryBuilder): string
    {
        return $queryBuilder->getDQL();
    }
}
