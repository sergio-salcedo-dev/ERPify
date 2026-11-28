<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Application\BankAccountFinder;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;

/**
 * The evidence a bank account holds, most recently attached first, with each document's opened name and state. An
 * erased document stays listed as its tombstone: the link is the account's record that it relied on it.
 */
final readonly class ListBankAccountEvidence
{
    public function __construct(
        private BankAccountFinder $accounts,
        private BankAccountEvidenceRepository $evidence,
        private DescribeEvidence $describe,
    ) {
    }

    /**
     * @throws InvalidUuidException         when $bankAccountId is not a UUID (400)
     * @throws BankAccountNotFoundException when no account has that id (404)
     *
     * @return list<ListedEvidence>
     */
    public function list(string $bankAccountId): array
    {
        $this->accounts->find($bankAccountId);

        return $this->describe->of($this->evidence->ofBankAccount(\strtolower($bankAccountId)));
    }
}
