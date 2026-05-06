<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Infrastructure\Persistence\PostgresBankRepository;

final readonly class BankDeleter
{
    public function __construct(
        private PostgresBankRepository $postgresBankRepository,
        private BankFinder $bankFinder,
    ) {
    }

    public function delete(string $id): void
    {
        $bank = $this->bankFinder->find($id);

        $this->postgresBankRepository->remove($bank);
    }
}
