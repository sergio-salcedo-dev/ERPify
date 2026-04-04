<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;

final class BankCreator
{
    public function __construct(private readonly BankRepository $repository)
    {
    }

    public function create(string $name, string $shortName): Bank
    {
        $bank = new Bank($name, $shortName);

        $this->repository->save($bank);

        return $bank;
    }
}
