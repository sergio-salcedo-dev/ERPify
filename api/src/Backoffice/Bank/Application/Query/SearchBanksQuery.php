<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Query;

use Erpify\Shared\Search\Domain\SearchCriteria;

/**
 * Read-side query for the bank list — the CQRS counterpart of the write-side
 * {@see \Erpify\Backoffice\Bank\Application\Command\CreateBankCommand}.
 *
 * It carries the generic {@see SearchCriteria} resolved from the shared HTTP
 * {@see \Erpify\Shared\Search\Application\Http\SearchQuery} (Epic 1 keeps that
 * boundary DTO generic and shared — search endpoints do not subclass it). The
 * controller builds this query and {@see \Erpify\Backoffice\Bank\Application\BankSearcher}
 * is its handler.
 */
final readonly class SearchBanksQuery
{
    public function __construct(public SearchCriteria $criteria)
    {
    }
}
