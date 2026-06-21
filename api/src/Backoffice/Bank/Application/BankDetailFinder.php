<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;

/**
 * Read-side handler for the single-bank DETAIL projection: loads the aggregate via {@see BankFinder}
 * and enriches it with its associated-account count through {@see BankAccountCountEnricher}. Kept
 * separate from {@see BankFinder} so the write-path loads (update, delete) stay pure and never drag
 * the cross-context read-model into a mutation flow.
 */
final readonly class BankDetailFinder
{
    public function __construct(
        private BankFinder $bankFinder,
        private BankAccountCountEnricher $accountCountEnricher,
    ) {
    }

    /**
     * @throws InvalidUuidException  when $id is not a well-formed RFC 4122 UUID (400 invalid-input)
     * @throws BankNotFoundException when no bank exists for the given $id (404)
     */
    public function find(string $id): Bank
    {
        $bank = $this->bankFinder->find($id);

        $this->accountCountEnricher->enrich($bank);

        return $bank;
    }
}
