<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Repository;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Domain\Search\PaginatedResult;
use Erpify\Shared\Domain\Search\SearchCriteria;

/**
 * Read-side search port — the swappable surface. Implementations may be backed
 * by the system of record (Doctrine) or by a projection (e.g. Elasticsearch);
 * aggregate writes stay on {@see BankRepository}.
 */
interface BankSearchRepository
{
    /** @return PaginatedResult<Bank> */
    public function search(SearchCriteria $criteria): PaginatedResult;
}
