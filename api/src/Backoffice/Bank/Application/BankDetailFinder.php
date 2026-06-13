<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Repository\AccountCountsByBank;
use Erpify\Shared\Domain\Uuid\InvalidUuidException;

/**
 * Read-side handler for the single-bank DETAIL projection: loads the aggregate via {@see BankFinder}
 * and enriches it with its associated-account count from the BankAccount read port. Kept separate
 * from {@see BankFinder} so the write-path loads (update, delete) stay pure and never drag the
 * cross-context read-model into a mutation flow.
 */
final readonly class BankDetailFinder
{
    public function __construct(
        private BankFinder $bankFinder,
        private AccountCountsByBank $accountCounts,
    ) {
    }

    /**
     * @throws InvalidUuidException  when $id is not a well-formed RFC 4122 UUID (400 invalid-input)
     * @throws BankNotFoundException when no bank exists for the given $id (404)
     */
    public function find(string $id): Bank
    {
        $bank = $this->bankFinder->find($id);

        // Key the count lookup by the hydrated aggregate id, not the raw route string: a UUID is
        // valid (and matches the row) in any letter case, but the count map is keyed by the
        // DB-canonical (lower-case) id, so an upper-case route id would otherwise miss and report 0.
        $bankId = $bank->getId();

        if (null !== $bankId) {
            $bank->assignAccountCount($this->accountCounts->countsByBankIds([$bankId])[$bankId] ?? 0);
        }

        return $bank;
    }
}
