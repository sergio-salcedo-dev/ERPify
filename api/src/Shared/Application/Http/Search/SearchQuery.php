<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Http\Search;

use Erpify\Shared\Domain\Search\PaginationMode;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * HTTP-boundary DTO for search endpoints.
 *
 * Decorated with `#[Assert\…]`; consumed by Symfony `#[MapQueryString]`
 * and validated automatically — failures emit `ValidationFailedException`
 * (422 by `SearchExceptionListener`).
 *
 * Per-entity DTOs extend this and add filter properties; they should
 * override `toCriteria()` to return their concrete `SearchCriteria` subtype.
 */
readonly class SearchQuery
{
    final public const int MAX_PAGE = 10_000;

    final public const int MAX_LIMIT = 1_000;

    /**
     * @param list<string>|null $ids
     */
    public function __construct(
        #[Assert\Length(max: 8192)]
        public ?string $cursor = null,
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(self::MAX_PAGE)]
        public ?int $page = 1,
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(self::MAX_LIMIT)]
        public ?int $limit = self::MAX_LIMIT,
        public PaginationMode $paginationMode = PaginationMode::LIGHT,
        #[Assert\All([new Assert\Uuid(strict: true)])]
        public ?array $ids = null,
    ) {
    }

    public function toCriteria(): SearchCriteria
    {
        return new SearchCriteria(
            cursor: $this->cursor,
            page: $this->page ?? 1,
            limit: $this->limit ?? self::MAX_LIMIT,
            paginationMode: $this->paginationMode,
            ids: $this->ids,
        );
    }
}
