<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search;

use Erpify\Shared\Domain\Search\Exception\InvalidPagination;

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
 * `limit` within [1, {@see MAX_LIMIT}] — so it holds for every adapter that builds a criteria
 * (HTTP, CLI, message handlers), not only the HTTP boundary DTO. Purely wire concerns (sparse
 * filter indexes, the untrusted value shapes / 422 violation format, `after` XOR `before`)
 * stay in {@see \Erpify\Shared\Application\Http\Search\SearchQuery}.
 */
final readonly class SearchCriteria
{
    /** Wire page-size default when the client omits `limit` (OQ-2: the default lives in the adapter). */
    public const int DEFAULT_LIMIT = 25;

    /** Wire page-size ceiling. Mirrors {@see \Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\WirePaginationPolicy::MAX_LIMIT}. */
    public const int MAX_LIMIT = 100;

    public function __construct(
        public ?string $cursor = null,
        public NavigationDirection $routingDirection = NavigationDirection::After,
        public int $limit = self::DEFAULT_LIMIT,
        public PaginationMode $paginationMode = PaginationMode::LIGHT,
        public Filters $filters = new Filters(),
        public ?string $sort = null,
        public ?SortDirection $direction = null,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw InvalidPagination::limitOutOfRange($limit, self::MAX_LIMIT);
        }
    }
}
