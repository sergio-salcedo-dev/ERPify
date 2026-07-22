<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Application\Http;

use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\Filters;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\PaginationMode;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Domain\SortDirection;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * HTTP-boundary DTO for search endpoints.
 *
 * Decorated with `#[Assert\…]`; consumed by Symfony `#[MapQueryString]`
 * and validated automatically — failures emit `ValidationFailedException`,
 * which {@see \Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory} maps
 * to a 422 `validation-failed` Problem Details body via
 * {@see \Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\ExceptionResponder}.
 *
 * Cursor-only navigation (PR3): `after`/`before` carry the OPAQUE keyset cursor
 * (base64url + HMAC); they are mutually exclusive (the callback below is layer 1
 * of the validation DAG, AR1/K2) and the present one fixes the
 * {@see NavigationDirection}. There is no `page` number. An omitted `limit` stays
 * `null` through to the criteria, where the engine resolves it from the active
 * policy's `defaultLimit` ({@see SearchCriteria::DEFAULT_LIMIT}, 25, on the wire
 * path); a supplied `limit` is capped at {@see SearchCriteria::MAX_LIMIT} (100) —
 * out of [1, 100] is a 422 `validation-failed`.
 *
 * Filtering is expressed exclusively through the generic `filters[]` grammar
 * ({@see FilterQuery}) resolved against each repository's field map — search
 * endpoints share this single DTO instead of subclassing it.
 */
final readonly class SearchQuery
{
    public const int MAX_FILTERS = 20;

    public const int MAX_SORT_LENGTH = 64;

    public const int MAX_CURSOR_LENGTH = 8192;

    /**
     * @param array<int, FilterQuery> $filters pre-validation the wire can deliver sparse
     *                                         indexes; the callback below rejects anything
     *                                         that is not a contiguous list from 0 (D1)
     */
    public function __construct(
        #[Assert\Length(max: self::MAX_CURSOR_LENGTH)]
        public ?string $after = null,
        #[Assert\Length(max: self::MAX_CURSOR_LENGTH)]
        public ?string $before = null,
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(SearchCriteria::MAX_LIMIT)]
        public ?int $limit = null,
        public PaginationMode $paginationMode = PaginationMode::LIGHT,
        #[Assert\Valid]
        #[Assert\Count(max: self::MAX_FILTERS)]
        public array $filters = [],
        // The semantic allow-list of sortable fields lives in each repository's SortFieldMap
        // (a 422 unknown-sort-field for anything outside it). The length cap is only a cheap
        // shape guard so an absurd value never reaches that lookup.
        #[Assert\Length(max: self::MAX_SORT_LENGTH)]
        public ?string $sort = null,
        public ?SortDirection $direction = null,
    ) {
    }

    /**
     * Mapping-time invariants — layer 1 of the validation DAG, surfaced as 422 `validation-failed`
     * before the handler runs (never a late decision):
     *   - filter indexes must be a contiguous list from 0 (D1);
     *   - `after`/`before` are mutually exclusive (AR1/K2) — a request carrying both has no single
     *     navigation intent.
     * Both live in this one `#[Assert\Callback]` so a request carrying either defect is rejected
     * at mapping time, in one place.
     */
    #[Assert\Callback]
    public function validateFilterIndexes(ExecutionContextInterface $context): void
    {
        if (!\array_is_list($this->filters)) {
            $context->buildViolation('Filter indexes must be contiguous and start at 0.')
                ->atPath('filters')
                ->addViolation()
            ;
        }

        if (null !== $this->after && null !== $this->before) {
            $context->buildViolation('Provide either `after` or `before`, never both.')
                ->atPath('after')
                ->addViolation()
            ;
        }
    }

    public function toCriteria(): SearchCriteria
    {
        // The wire param that arrived is the single routing authority (AR21): `before` → walk
        // backwards, otherwise (`after` present, or neither on the first page) walk forwards.
        // Mutual exclusion is already guaranteed by the callback above for the HTTP path.
        [$cursor, $routingDirection] = null !== $this->before
            ? [$this->before, NavigationDirection::Before]
            : [$this->after, NavigationDirection::After];

        return new SearchCriteria(
            cursor: $cursor,
            routingDirection: $routingDirection,
            // An omitted `limit` stays null: the engine resolves the page size from the policy.
            limit: $this->limit,
            paginationMode: $this->paginationMode,
            filters: $this->domainFilters(),
            // An empty `sort=` on the wire means "no sort" → the default order, not a 422
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
