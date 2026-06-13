<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Repository;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\SearchCriteria;

/**
 * Read-side keyset search port for the accounts of a single bank. The bank scope is an explicit
 * argument (not a {@see \Erpify\Shared\Domain\Search\Filter}) because it is a fixed route constraint,
 * not a client-tunable filter.
 */
interface BankAccountSearchRepository
{
    /**
     * @return Page<BankAccount>
     */
    public function search(string $bankId, SearchCriteria $criteria): Page;
}
