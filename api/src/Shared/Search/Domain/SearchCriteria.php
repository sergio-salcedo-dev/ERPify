<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Domain;

use Erpify\Shared\Search\Domain\Exception\InvalidPagination;

/**
 * Search criteria carried from the HTTP boundary down to search repositories: keyset
 * pagination inputs plus the generic {@see Filters}, resolved against each repository's
 * field map. Per-entity filtering is expressed through filters — never through subclassing
 * or extra typed properties.
 *
 * Cursor-only navigation (PR3): an OPAQUE `cursor` token plus the {@see NavigationDirection}
 * the wire param dictated. The pair is the single navigation state — there is no page number.
 * `routingDirection` is the sole routing authority (AR21), derived 1:1 from whether `after`
 * or `before` arrived; the cursor payload's own `dir` is only an integrity binding.
 *
 * The constructor enforces the one surviving pagination invariant ({@see InvalidPagination}):
 * an EXPLICIT `limit` within [1, {@see MAX_LIMIT}] — so it holds for every adapter that builds a
 * criteria (HTTP, CLI, message handlers), not only the HTTP boundary DTO. A `null` `limit` is the
 * "unspecified" sentinel: the criteria defers the page size to the engine, which resolves it from
 * the active policy's `defaultLimit` (an adapter selects its default by handing the engine a
 * different policy, never by baking one in here). Purely wire concerns (sparse filter indexes, the
 * untrusted value shapes / 422 violation format, `after` XOR `before`) stay in
 * {@see \Erpify\Shared\Search\Application\Http\SearchQuery}.
 */
final readonly class SearchCriteria
{
    /**
     * Wire-surface page size advertised to clients when they omit `limit` — the value the HTTP DTO
     * and navigation links materialize. It is NOT the criteria's constructor default (that is `null`,
     * the defer-to-policy sentinel); the engine's effective default is the active policy's
     * `defaultLimit`, which mirrors this on the wire path.
     */
    public const int DEFAULT_LIMIT = 25;

    /** Wire page-size ceiling. Mirrors {@see \Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\WirePaginationPolicy::MAX_LIMIT}. */
    public const int MAX_LIMIT = 100;

    public function __construct(
        public ?string $cursor = null,
        public NavigationDirection $routingDirection = NavigationDirection::After,
        // null = unspecified: the engine resolves the page size from the policy's `defaultLimit`.
        public ?int $limit = null,
        public PaginationMode $paginationMode = PaginationMode::LIGHT,
        public Filters $filters = new Filters(),
        public ?string $sort = null,
        public ?SortDirection $direction = null,
    ) {
        if (null !== $limit && ($limit < 1 || $limit > self::MAX_LIMIT)) {
            throw InvalidPagination::limitOutOfRange($limit, self::MAX_LIMIT);
        }
    }
}
