<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountHasLiveEvidence;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Erpify\Documents\Document\Application\Evidence\EvidenceDocuments;

/**
 * Removes an account's links on the way out of the account, or refuses while one of them still points at a document
 * that is not erased. The two removals of an account — its business deletion and its subject erasure — both call it
 * inside their own transaction, after locking the account's row, so no attach can slip a link in between the check
 * and the removal (the attach takes the same lock).
 *
 * Refusing rather than erasing the documents first is deliberate: a document is erased by its own operator action,
 * which re-proves the caller's password and writes the erasure ledger before the key is destroyed, and a person's or
 * an account's erasure never erases a document as a side effect. A document's state only moves toward `Erased`, so
 * a stale read can refuse in error but never remove a live link.
 */
final readonly class ReleaseBankAccountEvidence
{
    public function __construct(
        private BankAccountEvidenceRepository $evidence,
        private EvidenceDocuments $documents,
    ) {
    }

    /**
     * @throws BankAccountHasLiveEvidence when a linked document is not erased (409); nothing is removed
     */
    public function releaseAll(string $bankAccountId): void
    {
        $links = $this->evidence->ofBankAccount(\strtolower($bankAccountId));

        if ([] === $links) {
            return;
        }

        $filings = $this->documents->filingsOf(
            \array_map(static fn (BankAccountEvidence $link): string => $link->documentId(), $links),
        );

        foreach ($links as $link) {
            $filing = $filings[$link->documentId()] ?? null;

            if (null === $filing || !$filing->isErased()) {
                throw BankAccountHasLiveEvidence::withId($bankAccountId);
            }
        }

        $this->evidence->removeAll($links);
    }
}
