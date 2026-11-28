<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceNotFound;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Removes one link between a bank account and a document it held as evidence, so the document can be attached to the
 * account it really belongs to. The document itself is untouched — it stays filed, under its own erasure.
 *
 * Under the account's row lock, the lock the attach and the account's removal take; the change capture writes the
 * link's deletion row, its document id sealed under the account's scope.
 */
final readonly class DetachBankAccountEvidence
{
    public function __construct(
        private BankAccountRepository $accounts,
        private BankAccountEvidenceRepository $evidence,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws InvalidUuidException         when either id is not a UUID (400)
     * @throws BankAccountNotFoundException when no account has that id (404)
     * @throws BankAccountEvidenceNotFound  when the account holds no link with that id (404)
     */
    public function detach(string $bankAccountId, string $linkId): void
    {
        Uuid::ensure($bankAccountId);
        Uuid::ensure($linkId);
        $accountId = \strtolower($bankAccountId);
        $link = \strtolower($linkId);

        $this->transactionManager->transactional(function () use ($accountId, $link): void {
            if (null === $this->accounts->findByIdForUpdate($accountId)) {
                throw BankAccountNotFoundException::withId($accountId);
            }

            $this->evidence->removeAll([
                $this->evidence->findOnAccount($accountId, $link) ?? throw BankAccountEvidenceNotFound::withId($link),
            ]);
        });
    }
}
