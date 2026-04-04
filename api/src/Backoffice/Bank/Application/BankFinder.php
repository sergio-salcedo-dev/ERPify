<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Symfony\Component\Uid\Uuid;

final class BankFinder
{
    public function __construct(private readonly BankRepository $repository)
    {
    }

    public function find(Uuid $id): Bank
    {
        $bank = $this->repository->findById($id);

        if (!$bank instanceof Bank) {
            throw BankNotFoundException::withId($id);
        }

        return $bank;
    }
}
