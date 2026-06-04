<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\Http\BankSearchQuery;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankSearchRepository;
use Erpify\Shared\Domain\Search\PaginatedResult;

final readonly class BankSearcher
{
    public function __construct(private BankSearchRepository $bankSearchRepository)
    {
    }

    /**
     * @return PaginatedResult<Bank>
     */
    public function search(BankSearchQuery $query): PaginatedResult
    {
        return $this->bankSearchRepository->search($query->toCriteria());
    }
}
