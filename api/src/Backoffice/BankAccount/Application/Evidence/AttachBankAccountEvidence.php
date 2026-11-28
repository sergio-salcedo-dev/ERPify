<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Application\Command\AttachBankAccountEvidenceCommand;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceAlreadyAttached;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceDocumentErased;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceDocumentNotFound;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceRetentionMismatch;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Documents\Document\Application\Evidence\EvidenceDocuments;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use LogicException;

/**
 * Attaches a document an earlier upload filed (`UploadEvidence`, the two-step raw-body upload) to a bank account as
 * evidence. The original was filed by Documents and stays there; this records only that the account relies on it.
 *
 * In one transaction, under the account's row lock — the lock the account's deletion and its subject erasure take,
 * so a link and the removal of its account serialise instead of meeting at the foreign key:
 *
 *  1. the account must exist (404);
 *  2. the document must exist (422), must not be erased (409), and must have been filed under exactly the retention
 *     terms its kind fixes (422) — the server-side check that keeps an original declared under other terms out;
 *  3. the account must not hold it already (409; the unique constraint backs the check against a concurrent attach).
 *
 * The response describes the document through the same seam the listing uses, BEFORE the link is written, so the
 * erasure ledger's override refuses the attach (410) rather than answering a name that must not open.
 *
 * It records no domain event while nothing consumes one; the change capture writes the audit row of the link.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") one use case, each refusal its own domain exception
 */
final readonly class AttachBankAccountEvidence
{
    public function __construct(
        private BankAccountRepository $accounts,
        private BankAccountEvidenceRepository $evidence,
        private EvidenceDocuments $documents,
        private DescribeEvidence $describe,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws InvalidUuidException                 when $bankAccountId is not a UUID (400)
     * @throws BankAccountNotFoundException         when no account has that id (404)
     * @throws BankAccountEvidenceDocumentNotFound  when no document has the id the command names (422)
     * @throws BankAccountEvidenceDocumentErased    when that document is erased (409)
     * @throws BankAccountEvidenceRetentionMismatch when it was filed under other terms than its kind's (422)
     * @throws BankAccountEvidenceAlreadyAttached   when the account already holds it (409)
     */
    public function attach(string $bankAccountId, AttachBankAccountEvidenceCommand $command): ListedEvidence
    {
        Uuid::ensure($bankAccountId);
        $documentId = $command->documentId ?? throw new LogicException('The mapped command carries a document id.');
        $kind = $command->kind ?? throw new LogicException('The mapped command carries a kind.');
        Uuid::ensure($documentId);

        $accountId = \strtolower($bankAccountId);
        $documentId = \strtolower($documentId);

        return $this->transactionManager->transactional(
            fn (): ListedEvidence => $this->attachUnderLock($accountId, $documentId, $kind),
        );
    }

    private function attachUnderLock(
        string $bankAccountId,
        string $documentId,
        BankAccountEvidenceKind $kind,
    ): ListedEvidence {
        if (!$this->accounts->findByIdForUpdate($bankAccountId) instanceof BankAccount) {
            throw BankAccountNotFoundException::withId($bankAccountId);
        }

        $filing = $this->documents->filingOf($documentId)
            ?? throw BankAccountEvidenceDocumentNotFound::withId($documentId);

        if ($filing->isErased()) {
            throw BankAccountEvidenceDocumentErased::withId($documentId);
        }

        if (!$filing->isFiledUnder($kind->retentionReason(), $kind->legalBasis(), null)) {
            throw BankAccountEvidenceRetentionMismatch::of($documentId, $kind->value);
        }

        if ($this->evidence->holds($bankAccountId, $documentId)) {
            throw BankAccountEvidenceAlreadyAttached::of($bankAccountId, $documentId);
        }

        $link = BankAccountEvidence::attach(Uuid::generate(), $bankAccountId, $documentId, $kind);
        $described = $this->describe->of([$link]);
        $this->evidence->add($link);

        return $described[0] ?? throw BankAccountEvidenceDocumentNotFound::withId($documentId);
    }
}
