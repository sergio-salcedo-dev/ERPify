<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Http\Search;

use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Domain\Search\Filters;
use Erpify\Shared\Domain\Search\PaginationMode;
use Erpify\Shared\Domain\Search\SearchCriteria;
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
 * Per-entity DTOs extend this and add filter properties; they should
 * override `toCriteria()` to return their concrete `SearchCriteria` subtype.
 */
readonly class SearchQuery
{
    final public const int MAX_PAGE = 10_000;

    final public const int MAX_LIMIT = 1_000;

    final public const int MAX_FILTERS = 20;

    /**
     * @param list<string>|null       $ids
     * @param array<int, FilterQuery> $filters pre-validation the wire can deliver sparse
     *                                         indexes; the callback below rejects anything
     *                                         that is not a contiguous list from 0 (D1)
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
        #[Assert\Valid]
        #[Assert\Count(max: self::MAX_FILTERS)]
        public array $filters = [],
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
            limit: $this->limit ?? self::MAX_LIMIT,
            paginationMode: $this->paginationMode,
            ids: $this->ids,
            filters: $this->domainFilters(),
        );
    }

    final protected function domainFilters(): Filters
    {
        return Filters::fromList(\array_values(\array_map(
            static fn (FilterQuery $filterQuery): Filter => $filterQuery->toFilter(),
            $this->filters,
        )));
    }
}
