<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Http;

use Erpify\Backoffice\Bank\Domain\Search\BankSearchCriteria;
use Erpify\Shared\Application\Http\Search\FilterQuery;
use Erpify\Shared\Application\Http\Search\SearchQuery;
use Erpify\Shared\Domain\Search\PaginationMode;
use Override;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BankSearchQuery extends SearchQuery
{
    /**
     * The `@param` types below drive `#[MapQueryString]` denormalization: the serializer's
     * property-info extractor reads the docblock of the CONCRETE constructor it instantiates,
     * so `$filters` must re-declare its item type here even though the property is promoted
     * on the parent.
     *
     * @param list<string>|null       $ids
     * @param list<string>|null       $names
     * @param array<int, FilterQuery> $filters
     */
    public function __construct(
        ?string $cursor = null,
        ?int $page = 1,
        ?int $limit = self::MAX_LIMIT,
        PaginationMode $paginationMode = PaginationMode::LIGHT,
        ?array $ids = null,
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\Length(max: 255),
        ])]
        public ?array $names = null,
        array $filters = [],
    ) {
        parent::__construct($cursor, $page, $limit, $paginationMode, $ids, $filters);
    }

    #[Override]
    public function toCriteria(): BankSearchCriteria
    {
        return new BankSearchCriteria(
            cursor: $this->cursor,
            page: $this->page ?? 1,
            limit: $this->limit ?? self::MAX_LIMIT,
            paginationMode: $this->paginationMode,
            ids: $this->ids,
            names: $this->names,
            filters: $this->domainFilters(),
        );
    }
}
