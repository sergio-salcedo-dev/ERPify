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
use Erpify\Shared\Audit\Domain\SealedUnderOwnerScope;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Privacy\Domain\PersonalData;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * A document attached to a bank account as evidence of something about it — today, that the holder owns it. The link
 * references the account and the document by id only; neither aggregate holds the other, and the document stays the
 * Documents context's, reached through its published read seam.
 *
 * **A link table outside the account aggregate, on purpose.** The one set rule — a document is evidence of ONE
 * account — is the unique constraint on `document_id`, which no aggregate could hold anyway, so `BankAccount` stays
 * the aggregate it was. The attach and the detach still take the account's row lock: it is what makes a link and the
 * account's removal serialise rather than meet at the foreign key. Both foreign keys are physical, without cascade and
 * not deferrable (`BankAccountEvidence*ForeignKeySchemaListener`); the one to `document` is the justified
 * cross-context key recorded on its listener. A set invariant per account (a cap per kind, an ordering) reopens this
 * as a child entity of the account.
 *
 * It records no domain event while nothing consumes one, and names no person: who attached it is the audit row's
 * actor, never a column here. The document id is personal data in this consumer — a bank's ownership certificate
 * names the holder — so the change capture seals it under the ACCOUNT's scope ({@see SealedUnderOwnerScope}), and the
 * account's subject erasure, which destroys that key, shreds it in every row of the trail. The link is immutable —
 * no field has a setter and a change is a detach and a new attach — so the trail captures a creation and a deletion
 * and never an update.
 *
 * It maps itself rather than extending `AggregateRoot`, for the reason `DocumentCustody` does: that base collects
 * domain events this link never records and forces `created_at`/`updated_at` columns a row that is never updated
 * cannot have; `attached_at` is its one instant.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'bank_account_evidence')]
#[ORM\UniqueConstraint(name: self::DOCUMENT_CONSTRAINT, columns: ['document_id'])]
final readonly class BankAccountEvidence implements AuditedEntity, SealedUnderOwnerScope
{
    /** Named so a violation is classified by NAME: a concurrent attach of a document some account already holds. */
    public const string DOCUMENT_CONSTRAINT = 'uniq_bank_account_evidence_document';

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
        #[PersonalData]
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

    /** The account: its subject erasure destroys this scope's key, and every sealed document id of its links. */
    public function auditSealingOwner(): AuditResource
    {
        return AuditResource::of('BankAccount', $this->bankAccountId);
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
