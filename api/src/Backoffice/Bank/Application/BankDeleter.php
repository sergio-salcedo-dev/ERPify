<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Symfony\Component\Uid\Uuid;

final readonly class BankDeleter
{
    public function __construct(
        private BankRepository $bankRepository,
        private BankFinder $bankFinder,
    ) {}

    public function delete(Uuid $uuid): void
    {
        $bank = $this->bankFinder->find($uuid);

        $this->bankRepository->remove($bank);
    }
}
