<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Repository;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;

/**
 * Aggregate-lifecycle port backed by the system of record. The content-hash
 * queries feed stored-object orphan cleanup and must never be served from an
 * eventually-consistent read model — search lives on {@see BankSearchRepository}.
 */
interface BankRepository
{
    public function save(Bank $bank): void;

    public function remove(Bank $bank): void;

    public function findById(string $id): ?Bank;

    public function countBanksWithStoredObjectContentHash(string $contentHash): int;

    public function findStoredObjectMimeTypeByContentHash(string $contentHash): ?string;
}
