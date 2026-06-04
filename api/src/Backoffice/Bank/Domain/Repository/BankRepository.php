<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Repository;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;

/**
 * Aggregate-lifecycle port backed by the system of record. Search lives on
 * {@see BankSearchRepository}; stored-object reference queries on
 * {@see BankStoredObjectQueries}.
 */
interface BankRepository
{
    public function save(Bank $bank): void;

    public function remove(Bank $bank): void;

    public function findById(string $id): ?Bank;
}
