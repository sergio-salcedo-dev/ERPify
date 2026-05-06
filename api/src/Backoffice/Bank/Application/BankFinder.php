<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class BankFinder
{
    public function __construct(
        private BankRepository $bankRepository,
        private ValidatorInterface $validator,
    ) {
    }

    public function find(string $id): Bank
    {
        $constraintViolationList = $this->validator->validate($id, [new Assert\NotBlank(), new Assert\Uuid()]);

        if (\count($constraintViolationList) > 0) {
            throw new ValidationFailedException($id, $constraintViolationList);
        }

        $bank = $this->bankRepository->findById($id);

        if (!$bank instanceof Bank) {
            throw BankNotFoundException::withId($id);
        }

        return $bank;
    }
}
