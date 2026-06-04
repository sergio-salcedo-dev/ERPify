<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Repository;

/**
 * Stored-object reference queries consumed by the storage subsystem (orphan
 * cleanup, MIME resolution). Answers must come from the system of record —
 * never from an eventually-consistent read model — or cleanup could drop
 * blobs that are still referenced. Aggregate lifecycle lives on
 * {@see BankRepository}; search on {@see BankSearchRepository}.
 */
interface BankStoredObjectQueries
{
    public function countBanksWithStoredObjectContentHash(string $contentHash): int;

    public function findStoredObjectMimeTypeByContentHash(string $contentHash): ?string;
}
