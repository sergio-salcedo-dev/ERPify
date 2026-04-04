<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;

final class BankSearcher
{
    public function __construct(private readonly BankRepository $repository)
    {
    }

    /** @return Bank[] */
    public function search(): array
    {
        return $this->repository->search();
    }
}
