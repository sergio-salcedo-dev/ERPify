<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\Query\SearchBanksQuery;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankSearchRepository;
use Erpify\Shared\Domain\Search\PaginatedResult;

/**
 * Read-side handler for {@see SearchBanksQuery} — the query counterpart of the
 * write-side {@see BankCreator}.
 */
final readonly class BankSearcher
{
    public function __construct(private BankSearchRepository $bankSearchRepository)
    {
    }

    /**
     * @return PaginatedResult<Bank>
     */
    public function search(SearchBanksQuery $query): PaginatedResult
    {
        return $this->bankSearchRepository->search($query->criteria);
    }
}
