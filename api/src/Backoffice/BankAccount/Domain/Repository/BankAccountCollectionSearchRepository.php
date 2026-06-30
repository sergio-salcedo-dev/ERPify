<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Repository;

use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;

/**
 * Read-side keyset search port for accounts across ALL banks (the global collection), distinct from
 * the bank-scoped {@see BankAccountSearchRepository}. There is no bank-scope argument: the bank is a
 * client-tunable filter here, not a fixed route constraint.
 *
 * Each row is a {@see BankAccountCollectionRow} projection composing the account with its owning
 * bank's identity via a to-one JOIN (ADR D3), so the port returns the projection — never the
 * {@see \Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount} aggregate.
 */
interface BankAccountCollectionSearchRepository
{
    /**
     * @return Page<BankAccountCollectionRow>
     */
    public function search(SearchCriteria $criteria): Page;
}
