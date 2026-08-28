<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Repository;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;

/**
 * Aggregate-lifecycle port for bank accounts: the write surface (save/remove, plus a plain and a locking
 * read) and the referential-integrity count consumed by
 * {@see \Erpify\Backoffice\Bank\Application\BankDeleter}.
 * Paginated reads live on {@see BankAccountSearchRepository}; batched per-bank counts on the
 * read-only {@see BankAccountCounter}.
 */
interface BankAccountRepository
{
    public function save(BankAccount $account): void;

    public function remove(BankAccount $account): void;

    public function findById(string $id): ?BankAccount;

    /**
     * Loads the account under a pessimistic write lock, so two callers deciding the same aggregate's fate
     * serialise on its row: the second blocks until the first commits, then re-reads and finds whatever the
     * first left behind — **provided the caller has not already loaded this account in the same unit of
     * work**. With the entity managed, the lock routes through a refresh that hydrates nothing from a
     * vanished row and hands back the stale snapshot, so the loser would read the account as still present
     * and report an erasure that erased nothing. The one caller does not load it beforehand, which is what
     * makes the guarantee hold today rather than by construction; a second caller must clear the unit of
     * work first. Subject erasure needs it because its two effects — removing the record and
     * destroying the scope's key — are one transaction whose second half cannot be replayed: a competitor
     * that tombstones the key between an unlocked read and this transaction's own flush meets the change
     * capture sealing PII under a key that no longer exists, so a request whose only honest answer is
     * "already erased" fails instead. Must run inside a transaction.
     */
    public function findByIdForUpdate(string $id): ?BankAccount;

    public function countByBankId(string $bankId): int;
}
