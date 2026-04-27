<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search;

/**
 * Per-entity search criteria extend this class and add filter fields.
 */
readonly class SearchCriteria
{
    final public const int MAX_LIMIT = 1_000;

    /** @param list<string>|null $ids */
    public function __construct(
        public ?string $cursor = null,
        public int $page = 1,
        public int $limit = self::MAX_LIMIT,
        public PaginationMode $paginationMode = PaginationMode::LIGHT,
        public ?array $ids = null,
    ) {
    }
}
