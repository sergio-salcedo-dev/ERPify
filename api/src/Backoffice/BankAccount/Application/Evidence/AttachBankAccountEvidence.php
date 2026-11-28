<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Application\Command\AttachBankAccountEvidenceCommand;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\EvidenceKind;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Exception\EvidenceAlreadyAttached;
use Erpify\Backoffice\BankAccount\Domain\Exception\EvidenceAttachedElsewhere;
use Erpify\Backoffice\BankAccount\Domain\Exception\EvidenceDocumentUnavailable;
use Erpify\Backoffice\BankAccount\Domain\Exception\EvidenceRetentionMismatch;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Documents\Document\Application\Evidence\DescribeEvidenceDocuments;
use Erpify\Documents\Document\Application\Evidence\EvidenceDocument;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use LogicException;

/**
 * Attaches a document already filed through the Documents upload to a bank account as evidence of a kind.
 *
 * One transaction: the account row is locked `FOR UPDATE` first — the lock the deletion and the subject erasure take
 * before they release the account's links, so an attach and a removal are serialised on it and a link can never land
 * on an account that is being removed — then the document is checked and the link written. The document is checked
 * inside that transaction but not locked: an erasure that lands after the check leaves an attached, erased document,
 * which is a state the listing shows and the account's removal accepts, not a broken one. The check reads states only
 * ({@see DescribeEvidenceDocuments::statesOf()}), so no name is opened while the account's row is locked and a slow
 * keystore cannot hold the account's deletion; the answer therefore carries no name, which the listing gives.
 *
 * Refuses, in order: an unknown account (404), a document that does not exist, is erased or is quarantined (422), a
 * document filed under retention terms other than its kind's (422), a document already attached to this account (409)
 * and a document already attached to another one (409: a document is the evidence of one account).
 *
 * Accepted on purpose: a document still `received` or `scanning` (the screen attaches right after the upload, before
 * the scan), one whose scan failed (`scan_failed` can be scanned again and become readable; `quarantined` cannot), and
 * a `CLOSED` account (evidence about an account can still need filing after it closes; it then holds the account's
 * deletion until the document is erased, like any other live evidence). The list shows each document's state, and
 * only a `ready` one can be read.
 *
 * Each refusal is its own exception class and the document is read through the Documents seam, so the use case's
 * coupling sits above the default threshold by construction.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class AttachBankAccountEvidence
{
    private const string QUARANTINED = 'quarantined';

    public function __construct(
        private BankAccountRepository $accounts,
        private BankAccountEvidenceRepository $evidence,
        private DescribeEvidenceDocuments $documents,
        private TransactionManager $transactionManager,
    ) {
    }

    public function attach(string $bankAccountId, AttachBankAccountEvidenceCommand $command): AttachedEvidence
    {
        Uuid::ensure($bankAccountId);
        Uuid::ensure($command->documentId);
        $kind = $command->kind ?? throw new LogicException('The kind is validated as present before the use case.');
        $documentId = \strtolower($command->documentId);

        return $this->transactionManager->transactional(
            function () use ($bankAccountId, $documentId, $kind): AttachedEvidence {
                if (!$this->accounts->findByIdForUpdate($bankAccountId) instanceof BankAccount) {
                    throw BankAccountNotFoundException::withId($bankAccountId);
                }

                $this->documents->ensureTrustworthy();
                $document = $this->usableDocument($documentId, $kind);

                $existing = $this->evidence->findByDocumentId($documentId);

                if ($existing instanceof BankAccountEvidence) {
                    throw $existing->bankAccountId() === \strtolower($bankAccountId)
                        ? EvidenceAlreadyAttached::create()
                        : EvidenceAttachedElsewhere::create();
                }

                $link = BankAccountEvidence::attach(Uuid::generate(), $bankAccountId, $documentId, $kind);
                $this->evidence->save($link);

                return new AttachedEvidence($link, $document);
            },
        );
    }

    private function usableDocument(string $documentId, EvidenceKind $kind): EvidenceDocument
    {
        $document = $this->documents->statesOf([$documentId])[$documentId] ?? null;

        if (!$document instanceof EvidenceDocument || $document->erased || self::QUARANTINED === $document->state) {
            throw EvidenceDocumentUnavailable::create();
        }

        if (!$document->isRetainedAs($kind->retentionReason(), $kind->legalBasis(), null)) {
            throw EvidenceRetentionMismatch::forKind($kind);
        }

        return $document;
    }
}
