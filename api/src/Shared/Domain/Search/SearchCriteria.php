<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search;

use Erpify\Shared\Domain\Search\Exception\InvalidPagination;

/**
 * Search criteria carried from the HTTP boundary down to search repositories: pagination
 * inputs plus the generic {@see Filters}, resolved against each repository's field map.
 * Per-entity filtering is expressed through filters — never through subclassing or extra
 * typed properties.
 *
 * The constructor enforces the pagination invariants ({@see InvalidPagination}) — page and
 * limit each within [1, max] — so they hold for every adapter that builds a criteria (HTTP,
 * CLI, message handlers), not only the HTTP boundary DTO. Purely wire concerns (sparse filter
 * indexes, the untrusted value shapes / 422 violation format) stay in
 * {@see \Erpify\Shared\Application\Http\Search\SearchQuery}.
 */
final readonly class SearchCriteria
{
    public const int MAX_PAGE = 10_000;

    public const int MAX_LIMIT = 1_000;

    public function __construct(
        public ?string $cursor = null,
        public int $page = 1,
        public int $limit = self::MAX_LIMIT,
        public PaginationMode $paginationMode = PaginationMode::LIGHT,
        public Filters $filters = new Filters(),
        public ?string $sort = null,
        public ?SortDirection $direction = null,
    ) {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw InvalidPagination::pageOutOfRange($page, self::MAX_PAGE);
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw InvalidPagination::limitOutOfRange($limit, self::MAX_LIMIT);
        }
    }
}
