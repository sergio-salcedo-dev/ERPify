<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Symfony\Component\Uid\Uuid;

final class BankDeleter
{
    public function __construct(
        private readonly BankRepository $repository,
        private readonly BankFinder $finder,
    ) {
    }

    public function delete(Uuid $id): void
    {
        $bank = $this->finder->find($id);

        $this->repository->remove($bank);
    }
}
