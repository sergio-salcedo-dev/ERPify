<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Repository;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Domain\Search\PaginatedResult;
use Erpify\Shared\Domain\Search\SearchCriteria;

interface BankRepository
{
    public function findById(string $id): ?Bank;

    /** @return PaginatedResult<Bank> */
    public function search(SearchCriteria $criteria): PaginatedResult;

    public function countBanksWithStoredObjectContentHash(string $contentHash): int;

    public function findStoredObjectMimeTypeByContentHash(string $contentHash): ?string;
}
