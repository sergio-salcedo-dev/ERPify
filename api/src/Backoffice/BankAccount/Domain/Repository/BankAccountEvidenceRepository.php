<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Repository;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceAlreadyAttached;

/**
 * The documents attached to bank accounts. Every write goes through the ORM, one entity at a time, so the change
 * capture records each attach and each detach in the audit trail; a bulk DQL or DBAL write would bypass it.
 */
interface BankAccountEvidenceRepository
{
    /**
     * Persists and flushes the link.
     *
     * @throws BankAccountEvidenceAlreadyAttached when the account already holds that document, including a concurrent
     *                                            attach that committed first (the unique constraint, by name)
     */
    public function add(BankAccountEvidence $evidence): void;

    public function holds(string $bankAccountId, string $documentId): bool;

    /**
     * Every link of one account, most recently attached first. An account holds a handful of certificates, so the
     * list is not paginated.
     *
     * @return list<BankAccountEvidence>
     */
    public function ofBankAccount(string $bankAccountId): array;

    /**
     * Removes and flushes the given links, one entity at a time. Must run inside the transaction that removes their
     * account, after that account's row is locked.
     *
     * @param list<BankAccountEvidence> $links
     */
    public function removeAll(array $links): void;
}
