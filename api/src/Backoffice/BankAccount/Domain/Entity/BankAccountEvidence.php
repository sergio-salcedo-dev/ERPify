<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * A document attached to a bank account as evidence of something about it — today, that the holder owns it. The link
 * references the account and the document by id only; neither aggregate holds the other, and the document stays the
 * Documents context's, reached through its published read seam.
 *
 * **A link table outside the account aggregate, on purpose.** No set invariant over an account's evidence is the
 * account's to enforce — the one rule, "the same document once per account", is the unique constraint — so the link
 * does not need the account's lock to be added, and `BankAccount` stays the aggregate it was. Both foreign keys are
 * physical, without cascade and not deferrable (`BankAccountEvidence*ForeignKeySchemaListener`): the one to
 * `bank_account` makes a link and the account's deletion serialise, and the one to `document` is the justified
 * cross-context key recorded on its listener. A real set invariant (a cap per kind, an ordering) reopens this as a
 * child entity of the account.
 *
 * It records no domain event while nothing consumes one, and names no person: who attached it is the audit row's
 * actor, never a column here. It is immutable once attached — no field has a setter and a change is a detach and a
 * new attach — so the trail captures a creation and a deletion and never an update.
 *
 * It maps itself rather than extending `AggregateRoot`, for the reason `DocumentCustody` does: that base collects
 * domain events this link never records and forces `created_at`/`updated_at` columns a row that is never updated
 * cannot have; `attached_at` is its one instant.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'bank_account_evidence')]
#[ORM\UniqueConstraint(name: self::ACCOUNT_DOCUMENT_CONSTRAINT, columns: ['bank_account_id', 'document_id'])]
final readonly class BankAccountEvidence implements AuditedEntity
{
    /** Named so a violation is classified by NAME: a concurrent attach of the same document to the same account. */
    public const string ACCOUNT_DOCUMENT_CONSTRAINT = 'uniq_bank_account_evidence_account_document';

    /**
     * Microsecond precision through the project's own `datetimetz_immutable` type, so two documents attached in the
     * same second still list in the order they were attached.
     */
    #[ORM\Column(name: 'attached_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $attachedAt;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\Column(name: 'bank_account_id', type: Types::GUID)]
        private string $bankAccountId,
        #[ORM\Column(name: 'document_id', type: Types::GUID)]
        private string $documentId,
        #[ORM\Column(length: 32, enumType: BankAccountEvidenceKind::class)]
        private BankAccountEvidenceKind $kind,
    ) {
        Uuid::ensure($this->id);
        Uuid::ensure($this->bankAccountId);
        Uuid::ensure($this->documentId);
        $this->attachedAt = SystemClock::now();
    }

    /**
     * Ids are stored lower-cased: a UUID column compares case-insensitively, but the audit row's `resource_id` and any
     * id a caller compares against the wire are strings, and one spelling per link keeps them one value.
     */
    public static function attach(
        string $id,
        string $bankAccountId,
        string $documentId,
        BankAccountEvidenceKind $kind,
    ): self {
        return new self(\strtolower($id), \strtolower($bankAccountId), \strtolower($documentId), $kind);
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

    public function kind(): BankAccountEvidenceKind
    {
        return $this->kind;
    }

    public function attachedAt(): DateTimeImmutable
    {
        return $this->attachedAt;
    }

    /** A non-person type in `api/.audit-resource-types`: the row names a link, never a person. */
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
