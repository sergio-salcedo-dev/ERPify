<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Repository;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Infrastructure\Persistence\Paginator;
use Symfony\Component\Uid\Uuid;

interface BankRepository
{
    public function save(Bank $bank): void;

    public function remove(Bank $bank): void;

    public function findById(Uuid $uuid): ?Bank;

    /** @param array<string, mixed> $queryParams */
    public function search(array $queryParams): Paginator;

    public function countBanksWithStoredObjectContentHash(string $contentHash): int;

    public function findStoredObjectMimeTypeByContentHash(string $contentHash): ?string;
}
