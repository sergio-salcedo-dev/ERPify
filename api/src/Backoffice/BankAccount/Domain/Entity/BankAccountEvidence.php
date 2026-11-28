<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Backoffice\BankAccount\Domain\Enum\EvidenceKind;
use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * A document of the Documents context attached to a bank account as evidence, referenced by id on both sides: no
 * object graph crosses either module boundary, and the two foreign keys (to `bank_account` and to `document`, injected
 * by schema listeners, without cascade and not deferrable) are what keep a link from outliving either end.
 *
 * Outside the `BankAccount` aggregate on purpose: the account enforces no invariant over its set of evidence (no
 * count, no uniqueness of kind), so a collection on the aggregate would buy nothing but loading it. The one rule over
 * links — a document is the evidence of at most one account — is the table's `UNIQUE (document_id)`: a certificate
 * proves one account, and a document shared by two would be erased for both by an erasure decided for one. A real set
 * invariant on the account would reopen this as a child entity of the aggregate.
 *
 * A link pins the exact document attached, never its series: evidence is the original that was supplied, so a newer
 * version is attached on its own.
 *
 * Holds no person's id: who attached it is the audit row's actor, not a column (an `attached_by` would be a person
 * reference with an erasure owner to name). No domain event: nothing consumes one yet.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bank_account_evidence')]
#[ORM\UniqueConstraint(name: 'uniq_bank_account_evidence_document', columns: ['document_id'])]
final class BankAccountEvidence implements AuditedEntity
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID, unique: true)]
    private string $id;

    #[ORM\Column(name: 'bank_account_id', type: Types::GUID)]
    private string $bankAccountId;

    #[ORM\Column(name: 'document_id', type: Types::GUID)]
    private string $documentId;

    #[ORM\Column(name: 'attached_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $attachedAt;

    private function __construct(
        string $id,
        string $bankAccountId,
        string $documentId,
        #[ORM\Column(type: Types::TEXT, enumType: EvidenceKind::class)]
        private EvidenceKind $kind,
    ) {
        Uuid::ensure($id);
        Uuid::ensure($bankAccountId);
        Uuid::ensure($documentId);

        // Stored in the canonical (lower-case) spelling: the UNIQUE compares the column's text, so an upper-cased id
        // would otherwise be a second link to the same document.
        $this->id = \strtolower($id);
        $this->bankAccountId = \strtolower($bankAccountId);
        $this->documentId = \strtolower($documentId);
        $this->attachedAt = SystemClock::now();
    }

    public static function attach(string $id, string $bankAccountId, string $documentId, EvidenceKind $kind): self
    {
        return new self($id, $bankAccountId, $documentId, $kind);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function bankAccountId(): string
    {
        return $this->bankAccountId;
    }

    public function documentId(): string
    {
        return $this->documentId;
    }

    public function kind(): EvidenceKind
    {
        return $this->kind;
    }

    public function attachedAt(): DateTimeImmutable
    {
        return $this->attachedAt;
    }

    public function auditResource(): AuditResource
    {
        return AuditResource::of('BankAccountEvidence', $this->id);
    }

    public function auditAction(AuditWriteOperation $operation): string
    {
        return match ($operation) {
            AuditWriteOperation::CREATED => 'BANK_ACCOUNT_EVIDENCE_CREATED',
            AuditWriteOperation::UPDATED => 'BANK_ACCOUNT_EVIDENCE_UPDATED',
            AuditWriteOperation::DELETED => 'BANK_ACCOUNT_EVIDENCE_DELETED',
        };
    }
}
