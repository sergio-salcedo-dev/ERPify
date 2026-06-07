<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search;

/**
 * Search criteria carried from the HTTP boundary down to search repositories: pagination
 * inputs plus the generic {@see Filters}, resolved against each repository's field map.
 * Per-entity filtering is expressed through filters — never through subclassing or extra
 * typed properties.
 */
final readonly class SearchCriteria
{
    public const int MAX_LIMIT = 1_000;

    public function __construct(
        public ?string $cursor = null,
        public int $page = 1,
        public int $limit = self::MAX_LIMIT,
        public PaginationMode $paginationMode = PaginationMode::LIGHT,
        public Filters $filters = new Filters(),
    ) {
    }
}
