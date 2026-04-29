<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Search;

use Erpify\Shared\Domain\Search\PaginationMode;
use Erpify\Shared\Domain\Search\SearchCriteria;

final readonly class BankSearchCriteria extends SearchCriteria
{
    /**
     * @param list<string>|null $ids
     * @param list<string>|null $names
     */
    public function __construct(
        ?string $cursor = null,
        int $page = 1,
        ?int $limit = null,
        PaginationMode $paginationMode = PaginationMode::LIGHT,
        ?array $ids = null,
        public ?array $names = null,
    ) {
        parent::__construct($cursor, $page, $limit, $paginationMode, $ids);
    }
}
