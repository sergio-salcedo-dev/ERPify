<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;

final readonly class BankFinder
{
    public function __construct(
        private BankRepository $bankRepository,
    ) {
    }

    /**
     * A malformed id is a request-target syntax error, surfaced as 400 `invalid-input`
     * (via {@see InvalidUuidException}); a well-formed id with no matching row is 404.
     *
     * @throws InvalidUuidException  when $id is not a well-formed RFC 4122 UUID
     * @throws BankNotFoundException when no bank exists for the given $id
     */
    public function find(string $id): Bank
    {
        Uuid::ensure($id);

        $bank = $this->bankRepository->findById($id);

        if (!$bank instanceof Bank) {
            throw BankNotFoundException::withId($id);
        }

        return $bank;
    }
}
