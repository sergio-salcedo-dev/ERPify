<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Repository;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;

/**
 * Aggregate-lifecycle port for bank accounts: the write surface (save/remove/findById) plus the
 * referential-integrity count consumed by {@see \Erpify\Backoffice\Bank\Application\BankDeleter}.
 * Paginated reads live on {@see BankAccountSearchRepository}; batched per-bank counts on the
 * read-only {@see BankAccountCounter}.
 */
interface BankAccountRepository
{
    public function save(BankAccount $account): void;

    public function remove(BankAccount $account): void;

    public function findById(string $id): ?BankAccount;

    public function countByBankId(string $bankId): int;
}
