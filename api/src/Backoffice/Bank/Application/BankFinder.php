<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Symfony\Component\Uid\Uuid;

final readonly class BankFinder
{
    public function __construct(private BankRepository $bankRepository) {}

    public function find(Uuid $uuid): Bank
    {
        $bank = $this->bankRepository->findById($uuid);

        if (!$bank instanceof Bank) {
            throw BankNotFoundException::withId($uuid);
        }

        return $bank;
    }
}
