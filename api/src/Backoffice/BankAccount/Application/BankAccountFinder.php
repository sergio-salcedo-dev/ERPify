<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;

final readonly class BankAccountFinder
{
    public function __construct(
        private BankAccountRepository $bankAccountRepository,
    ) {
    }

    /**
     * A malformed id is a request-target syntax error, surfaced as 400 `invalid-input`
     * (via {@see InvalidUuidException}); a well-formed id with no matching row is 404.
     *
     * @throws InvalidUuidException         when $id is not a well-formed RFC 4122 UUID
     * @throws BankAccountNotFoundException when no account exists for the given $id
     */
    public function find(string $id): BankAccount
    {
        Uuid::ensure($id);

        $account = $this->bankAccountRepository->findById($id);

        if (!$account instanceof BankAccount) {
            throw BankAccountNotFoundException::withId($id);
        }

        return $account;
    }
}
