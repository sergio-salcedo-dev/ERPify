<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Repository;

use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;

/**
 * Read-side exact-match port for looking up ONE account by its (unique) IBAN across all banks — the
 * non-logging counterpart to the generic {@see BankAccountCollectionSearchRepository} `filters[]`
 * vocabulary, which never carries a field this sensitive (see the IBAN wire contract in
 * `adding-endpoints.md`). `bank_account.iban` is `unique`, so this is a single-row lookup, never a
 * paginated {@see \Erpify\Shared\Search\Domain\Page}.
 */
interface BankAccountIbanLookupRepository
{
    public function findByIban(string $canonicalIban): ?BankAccountCollectionRow;
}
