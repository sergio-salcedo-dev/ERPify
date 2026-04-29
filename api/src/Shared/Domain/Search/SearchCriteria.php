<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search;

/**
 * Universal search criteria carried into Domain repositories.
 *
 * Values originate at the HTTP boundary (Application-layer DTO with
 * `#[Assert\…]` attributes) and are validated there. Domain trusts the
 * shape and only re-checks invariants that a typed property cannot
 * already guarantee.
 *
 * Per-entity search criteria extend this class and add filter fields.
 */
readonly class SearchCriteria
{
    /**
     * @param list<string>|null $ids
     */
    public function __construct(
        public ?string $cursor = null,
        public int $page = 1,
        public ?int $limit = null,
        public PaginationMode $paginationMode = PaginationMode::LIGHT,
        public ?array $ids = null,
    ) {
    }
}
