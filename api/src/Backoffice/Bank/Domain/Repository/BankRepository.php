<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Repository;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Symfony\Component\Uid\Uuid;

interface BankRepository
{
    public function save(Bank $bank): void;

    public function remove(Bank $bank): void;

    public function findById(Uuid $id): ?Bank;

    /** @return Bank[] */
    public function search(): array;

    public function countBanksWithStoredObjectContentHash(string $contentHash): int;

    public function findStoredObjectMimeTypeByContentHash(string $contentHash): ?string;
}
