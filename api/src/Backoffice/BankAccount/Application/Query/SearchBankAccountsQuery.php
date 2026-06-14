<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Query;

use Erpify\Shared\Domain\Search\SearchCriteria;

/**
 * Read-side query for a bank's accounts list. Carries the generic {@see SearchCriteria} resolved from
 * the shared HTTP {@see \Erpify\Shared\Application\Http\Search\SearchQuery}; the bank scope travels
 * separately (route id), not as a criteria filter.
 */
final readonly class SearchBankAccountsQuery
{
    public function __construct(public SearchCriteria $criteria)
    {
    }
}
