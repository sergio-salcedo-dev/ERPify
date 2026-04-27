<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Infrastructure\Persistence\Paginator;

final readonly class BankSearcher
{
    public function __construct(private BankRepository $bankRepository)
    {
    }

    /** @param array<string, mixed> $queryParams */
    public function search(array $queryParams): Paginator
    {
        return $this->bankRepository->search($queryParams);
    }
}
