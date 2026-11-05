<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;

/**
 * One row of a document's custody trail: a fact that happened to the document, who caused it and when.
 *
 * **Append-only after insert, and the only exception is the actor.** `readOnly: true` makes the ORM emit no
 * `UPDATE` for it, no code path removes a row, and the single statement that ever rewrites one is the actor
 * anonymisation run by a person's erasure (`DoctrineDocumentCustodyRepository::anonymiseActor()`), which writes
 * {@see ErasedPersonMarker::UUID}. `make php.lint.document-custody` holds
 * that set closed. Custody is therefore no longer absolutely append-only, and is not claimed cryptographically
 * immutable (`docs/adr/documents-custody-log.md`).
 *
 * `seq` numbers a document's rows from 1 with no gap among committed rows; it is computed under the lock on
 * the document row and `UNIQUE (document_id, seq)` is the second defence. Rows are never deleted: the foreign
 * key to `document` has no cascade, and a document is erased by changing its state, never by deleting it.
 *
 * It maps itself rather than extending `AggregateRoot`, for the reason `Image` does: that base collects
 * domain events this log never records and forces an `updated_at` a row that is never updated cannot have.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'document_custody')]
#[ORM\UniqueConstraint(name: self::SEQUENCE_CONSTRAINT, columns: ['document_id', 'seq'])]
#[ORM\Index(name: 'idx_document_custody_actor_id', columns: ['actor_id'])]
final readonly class DocumentCustody
{
    /**
     * Named so a violation is classified by NAME: this one is a lost race and is retried, whereas the
     * document table's idempotency constraint is a conflict and is not.
     */
    public const string SEQUENCE_CONSTRAINT = 'uniq_document_custody_document_seq';

    /**
     * Microsecond precision through the project's own `datetimetz_immutable` type, so two rows of one
     * document filed in the same second still carry distinguishable stamps.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\Column(name: 'document_id', type: Types::GUID)]
        private string $documentId,
        #[ORM\Column(type: Types::INTEGER)]
        private int $seq,
        #[ORM\Column(length: 32, enumType: CustodyEvent::class)]
        private CustodyEvent $event,
        #[ORM\Column(name: 'actor_id', type: Types::GUID)]
        #[PersonSubjectReference(erasedBy: 'src/Documents/Document/Application/PurgeUserDocumentReferences.php')]
        private string $actorId,
    ) {
        Uuid::ensure($this->id);
        Uuid::ensure($this->documentId);
        Uuid::ensure($this->actorId);

        if (ErasedPersonMarker::UUID === $this->actorId) {
            throw new InvalidArgumentException('The erased-person marker is written by the erasure, never recorded.');
        }

        if ($this->seq < 1) {
            throw new InvalidArgumentException('A custody sequence number starts at 1.');
        }

        $this->createdAt = SystemClock::now();
    }

    public static function record(string $id, string $documentId, int $seq, CustodyEvent $event, string $actorId): self
    {
        return new self($id, $documentId, $seq, $event, $actorId);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function documentId(): string
    {
        return $this->documentId;
    }

    public function seq(): int
    {
        return $this->seq;
    }

    public function event(): CustodyEvent
    {
        return $this->event;
    }

    public function actorId(): string
    {
        return $this->actorId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
