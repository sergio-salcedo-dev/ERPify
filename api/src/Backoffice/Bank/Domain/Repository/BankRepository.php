<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Repository;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;

/**
 * Aggregate-lifecycle port backed by the system of record. Search lives on
 * {@see BankSearchRepository}.
 */
interface BankRepository
{
    public function save(Bank $bank): void;

    public function remove(Bank $bank): void;

    public function findById(string $id): ?Bank;

    /**
     * Loads the bank holding its row lock (`FOR UPDATE`) until the surrounding transaction ends, from the database
     * and never from the identity map, so the state read is the state the lock froze. Must be called inside a
     * transaction. A bank that no longer exists is `null`.
     */
    public function findByIdForUpdate(string $id): ?Bank;
}
