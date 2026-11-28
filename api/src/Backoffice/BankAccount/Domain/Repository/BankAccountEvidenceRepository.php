<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Repository;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;

interface BankAccountEvidenceRepository
{
    /**
     * Persists and flushes the link. A second link of the same document, to any account, is refused by the table's
     * UNIQUE and surfaces as {@see \Erpify\Backoffice\BankAccount\Domain\Exception\EvidenceAlreadyAttached}.
     */
    public function save(BankAccountEvidence $evidence): void;

    /**
     * Every link of one account, oldest first (the id breaks a tie).
     *
     * @return list<BankAccountEvidence>
     */
    public function findByBankAccountId(string $bankAccountId): array;

    /**
     * The link holding this document, whichever account it belongs to; a document has at most one.
     */
    public function findByDocumentId(string $documentId): ?BankAccountEvidence;

    /**
     * Removes the given links and flushes, so each leaves its audit row in the caller's transaction.
     *
     * @param list<BankAccountEvidence> $links
     */
    public function removeAll(array $links): void;
}
