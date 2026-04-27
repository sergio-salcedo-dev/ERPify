<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Http;

use Erpify\Backoffice\Bank\Domain\Search\BankSearchCriteria;
use Erpify\Shared\Application\Http\Search\SearchQuery;
use Erpify\Shared\Domain\Search\PaginationMode;
use Override;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BankSearchQuery extends SearchQuery
{
    /**
     * @param list<string>|null $ids
     * @param list<string>|null $names
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
    ) {
        parent::__construct($cursor, $page, $limit, $paginationMode, $ids);
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
        );
    }
}
