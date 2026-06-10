<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Http\Search;

use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Domain\Search\Filters;
use Erpify\Shared\Domain\Search\PaginationMode;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Domain\Search\SortDirection;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * HTTP-boundary DTO for search endpoints.
 *
 * Decorated with `#[Assert\…]`; consumed by Symfony `#[MapQueryString]`
 * and validated automatically — failures emit `ValidationFailedException`,
 * which {@see \Erpify\Shared\Application\Problem\ProblemDetailsFactory} maps
 * to a 400 `validation-failed` Problem Details body via
 * {@see \Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder}.
 *
 * Filtering is expressed exclusively through the generic `filters[]` grammar
 * ({@see FilterQuery}) resolved against each repository's field map — search
 * endpoints share this single DTO instead of subclassing it.
 */
final readonly class SearchQuery
{
    public const int MAX_FILTERS = 20;

    public const int MAX_SORT_LENGTH = 64;

    /**
     * @param array<int, FilterQuery> $filters pre-validation the wire can deliver sparse
     *                                         indexes; the callback below rejects anything
     *                                         that is not a contiguous list from 0 (D1)
     */
    public function __construct(
        #[Assert\Length(max: 8192)]
        public ?string $cursor = null,
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(SearchCriteria::MAX_PAGE)]
        public ?int $page = 1,
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(SearchCriteria::MAX_LIMIT)]
        public ?int $limit = SearchCriteria::MAX_LIMIT,
        public PaginationMode $paginationMode = PaginationMode::LIGHT,
        #[Assert\Valid]
        #[Assert\Count(max: self::MAX_FILTERS)]
        public array $filters = [],
        // The semantic allow-list of sortable fields lives in each repository's SortFieldMap
        // (a 400 unknown-sort-field for anything outside it). The length cap is only a cheap
        // shape guard so an absurd value never reaches that lookup.
        #[Assert\Length(max: self::MAX_SORT_LENGTH)]
        public ?string $sort = null,
        public ?SortDirection $direction = null,
    ) {
    }

    #[Assert\Callback]
    public function validateFilterIndexes(ExecutionContextInterface $context): void
    {
        if (\array_is_list($this->filters)) {
            return;
        }

        $context->buildViolation('Filter indexes must be contiguous and start at 0.')
            ->atPath('filters')
            ->addViolation()
        ;
    }

    public function toCriteria(): SearchCriteria
    {
        return new SearchCriteria(
            cursor: $this->cursor,
            page: $this->page ?? 1,
            limit: $this->limit ?? SearchCriteria::MAX_LIMIT,
            paginationMode: $this->paginationMode,
            filters: $this->domainFilters(),
            // An empty `sort=` on the wire means "no sort" → the default order, not a 400
            // unknown-sort-field; the criteria never carries a meaningless empty field name.
            sort: '' === $this->sort ? null : $this->sort,
            direction: $this->direction,
        );
    }

    private function domainFilters(): Filters
    {
        return Filters::fromList(\array_values(\array_map(
            static fn (FilterQuery $filterQuery): Filter => $filterQuery->toFilter(),
            $this->filters,
        )));
    }
}
