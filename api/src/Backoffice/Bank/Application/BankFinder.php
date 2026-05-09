<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Application\Validation\Validator;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class BankFinder
{
    public function __construct(
        private BankRepository $bankRepository,
        private Validator $validator,
    ) {
    }

    /**
     * @throws BankNotFoundException
     * @throws ValidationFailedException
     */
    public function find(string $id): Bank
    {
        $this->validator->ensure(
            $id,
            [new Assert\NotBlank(), new Assert\Uuid(strict: true)],
            propertyPath: 'id',
        );

        $bank = $this->bankRepository->findById($id);

        if (!$bank instanceof Bank) {
            throw BankNotFoundException::withId($id);
        }

        return $bank;
    }
}
