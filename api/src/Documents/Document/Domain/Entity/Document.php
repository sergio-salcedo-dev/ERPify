<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\IntakeMetadata;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;

/**
 * The current snapshot of one evidence-class binary. State-oriented on purpose: the business needs where the
 * document stands now, while the history of how it got there lives in `document_custody`, appended in the
 * same transaction as the snapshot change (`docs/adr/documents-custody-log.md`).
 *
 * **Each version is its own aggregate.** `seriesId` links it to its series by a scalar and `predecessorId` to
 * the version it replaces; nothing else about versioning exists. Both are written once and have no setter and
 * no UPDATE path (`updatable: false`): this story only files first versions, so `seriesId` is the row's own
 * id and `predecessorId` is `NULL`, and no series aggregate, cycle check or walk-up exists to maintain them.
 *
 * **`scopeId` is random and never derived from the document id.** It names the encryption scope that seals
 * the bytes (`EncryptionScopeId::forDocument()`); a scope derived from the document id would let anyone who
 * knows the id compute the scope, and two documents sharing a scope would let one's erasure shred the other's
 * bytes, which is why it carries its own `UNIQUE`. It is opaque, immutable, never exposed in a path and never
 * reused, since documents are never physically deleted.
 *
 * **The uploader is a person reference** (`#[PersonSubjectReference]`). A person's erasure rewrites it to
 * {@see ErasedPersonMarker::UUID} and never touches the document: the
 * uploader is not the subject of the evidence. That rewrite is a bulk DBAL statement, so no code path loads a
 * `Document` to anonymise it.
 *
 * The idempotency key hash is the actor-scoped hash of the caller's key, held on the mutable snapshot so the
 * same purge that rewrites the uploader can null it; replay protection for that actor ends there.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the accessors of one snapshot; there is no behaviour yet to split
 */
#[ORM\Entity]
#[ORM\Table(name: 'document')]
#[ORM\UniqueConstraint(name: self::SCOPE_ID_CONSTRAINT, columns: ['scope_id'])]
#[ORM\UniqueConstraint(name: self::IDEMPOTENCY_KEY_CONSTRAINT, columns: ['idempotency_key_hash'])]
#[ORM\Index(name: 'idx_document_uploader_id', columns: ['uploader_id'])]
final class Document extends AggregateRoot
{
    /**
     * Constraint names are explicit because a unique violation is classified by NAME: the idempotency key
     * is a conflict, never retried, and must not be confused with the custody sequence constraint, which is.
     */
    public const string SCOPE_ID_CONSTRAINT = 'uniq_document_scope_id';

    public const string IDEMPOTENCY_KEY_CONSTRAINT = 'uniq_document_idempotency_key_hash';

    private const int DIGEST_HEX_LENGTH = 64;

    private const string NEXT_SCAN_AT_FALLBACK = '1970-01-01 00:00:00+00';

    #[ORM\Column(name: 'scope_id', type: Types::GUID, updatable: false)]
    private string $scopeId;

    #[ORM\Column(name: 'series_id', type: Types::GUID, updatable: false)]
    private string $seriesId;

    // Only first versions are filed here; a successor is a different way in, and it brings its own argument.
    #[ORM\Column(name: 'predecessor_id', type: Types::GUID, nullable: true, updatable: false)]
    // @phpstan-ignore property.unusedType (Doctrine hydrates a successor's id; only first versions are filed here)
    private ?string $predecessorId = null;

    #[ORM\Column(name: 'uploader_id', type: Types::GUID)]
    #[PersonSubjectReference(erasedBy: 'src/Documents/Document/Application/PurgeUserDocumentReferences.php')]
    private string $uploaderId;

    #[ORM\Column(length: 16, enumType: DocumentState::class)]
    private DocumentState $state = DocumentState::Received;

    #[ORM\Column(length: self::DIGEST_HEX_LENGTH, updatable: false)]
    private string $digest;

    #[ORM\Column(name: 'size_bytes', type: Types::INTEGER, updatable: false)]
    private int $sizeBytes;

    #[ORM\Column(name: 'idempotency_key_hash', length: self::DIGEST_HEX_LENGTH, nullable: true)]
    private ?string $idempotencyKeyHash;

    /**
     * The original filename sealed under this document's own scope, written in the commit transaction by the
     * upload story and set to NULL by the `Erased` transition. Never plaintext.
     */
    #[ORM\Column(name: 'filename_ciphertext', type: Types::TEXT, nullable: true)]
    private ?string $filenameCiphertext;

    /** Closed vocabulary supplied by the intake flow; its values are defined by the upload story, hence no CHECK. */
    #[ORM\Column(name: 'retention_reason', length: 32, nullable: true)]
    private ?string $retentionReason;

    /** GDPR Article 6 basis; its values are defined by the upload story, hence no CHECK. */
    #[ORM\Column(name: 'legal_basis', length: 32, nullable: true)]
    private ?string $legalBasis;

    #[ORM\Column(name: 'review_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $reviewAt;

    /**
     * Set once the sealed object is in the final namespace and its directory synced, by the writer after the
     * commit or by the roll-forward sweep, through a compare-and-set statement; the scan claim requires it.
     * Never written through the ORM.
     */
    #[ORM\Column(
        name: 'promoted_at',
        type: Types::DATETIMETZ_IMMUTABLE,
        nullable: true,
        insertable: false,
        updatable: false,
    )]
    // @phpstan-ignore property.unusedType (Doctrine hydrates it; a compare-and-set statement writes it)
    private ?DateTimeImmutable $promotedAt = null;

    private function __construct(
        string $id,
        string $scopeId,
        string $uploaderId,
        string $digest,
        int $sizeBytes,
        ?string $idempotencyKeyHash,
        IntakeMetadata $intake,
        /**
         * When the scan sweep may next claim the document. Filed as the instant the document was received — the
         * upload commit passes its transaction's database time — so one sweep also re-drives a lost first message.
         * The column keeps a literal default (the epoch, due at once) only so an image that predates it can still
         * insert a row; every writer here names it.
         */
        #[ORM\Column(
            name: 'next_scan_at',
            type: Types::DATETIMETZ_IMMUTABLE,
            options: ['default' => self::NEXT_SCAN_AT_FALLBACK],
        )]
        private DateTimeImmutable $nextScanAt,
    ) {
        parent::__construct();

        $this->ensureIdentifiers($id, $scopeId, $uploaderId);
        $this->ensureContent($digest, $sizeBytes, $idempotencyKeyHash);

        $this->id = $id;
        $this->scopeId = $scopeId;
        $this->seriesId = $id;
        $this->uploaderId = $uploaderId;
        $this->digest = $digest;
        $this->sizeBytes = $sizeBytes;
        $this->idempotencyKeyHash = $idempotencyKeyHash;
        $this->filenameCiphertext = $intake->filenameCiphertext;
        $this->retentionReason = $intake->retentionReason;
        $this->legalBasis = $intake->legalBasis;
        $this->reviewAt = $intake->reviewAt;
    }

    /**
     * Files the first version of a document in `Received`. The caller computes the digest over the original
     * and supplies the random scope id; nothing here reads a byte.
     */
    public static function receive(
        string $id,
        string $scopeId,
        string $uploaderId,
        string $digest,
        int $sizeBytes,
        ?string $idempotencyKeyHash = null,
        ?IntakeMetadata $intake = null,
        ?DateTimeImmutable $receivedAt = null,
    ): self {
        return new self(
            $id,
            $scopeId,
            $uploaderId,
            $digest,
            $sizeBytes,
            $idempotencyKeyHash,
            $intake ?? new IntakeMetadata(),
            $receivedAt ?? SystemClock::now(),
        );
    }

    /** The id as a non-null string: a filed document always has one. */
    public function documentId(): string
    {
        return $this->id();
    }

    public function scopeId(): string
    {
        return $this->scopeId;
    }

    public function seriesId(): string
    {
        return $this->seriesId;
    }

    public function predecessorId(): ?string
    {
        return $this->predecessorId;
    }

    public function uploaderId(): string
    {
        return $this->uploaderId;
    }

    public function state(): DocumentState
    {
        return $this->state;
    }

    public function digest(): string
    {
        return $this->digest;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function idempotencyKeyHash(): ?string
    {
        return $this->idempotencyKeyHash;
    }

    public function filenameCiphertext(): ?string
    {
        return $this->filenameCiphertext;
    }

    public function retentionReason(): ?string
    {
        return $this->retentionReason;
    }

    public function legalBasis(): ?string
    {
        return $this->legalBasis;
    }

    public function reviewAt(): ?DateTimeImmutable
    {
        return $this->reviewAt;
    }

    public function promotedAt(): ?DateTimeImmutable
    {
        return $this->promotedAt;
    }

    public function nextScanAt(): DateTimeImmutable
    {
        return $this->nextScanAt;
    }

    /**
     * Every identifier is a lower-case UUID, so one row has one spelling, and the scope is never the document.
     */
    private function ensureIdentifiers(string $id, string $scopeId, string $uploaderId): void
    {
        foreach ([$id, $scopeId, $uploaderId] as $identifier) {
            Uuid::ensure($identifier);

            if (\strtolower($identifier) !== $identifier) {
                throw new InvalidArgumentException('Identifiers must be lower-case UUIDs.');
            }
        }

        if (ErasedPersonMarker::UUID === $uploaderId) {
            throw new InvalidArgumentException('The erased-person marker is written by the erasure, never filed.');
        }

        if ($scopeId === $id) {
            throw new InvalidArgumentException('The encryption scope id must not be the document id.');
        }
    }

    private function ensureContent(string $digest, int $sizeBytes, ?string $idempotencyKeyHash): void
    {
        if ($this->isNotHexDigest($digest)) {
            throw new InvalidArgumentException(
                'The digest must be a 64-character lower-case hexadecimal SHA-256 string.',
            );
        }

        if (null !== $idempotencyKeyHash && $this->isNotHexDigest($idempotencyKeyHash)) {
            throw new InvalidArgumentException(
                'The idempotency key hash must be a 64-character lower-case hexadecimal string.',
            );
        }

        if ($sizeBytes <= 0) {
            throw new InvalidArgumentException('The size must be a positive number of bytes.');
        }
    }

    /**
     * `D` is load-bearing: without it `$` also matches before a trailing newline.
     */
    private function isNotHexDigest(string $value): bool
    {
        return 1 !== \preg_match('/^[0-9a-f]{64}$/D', $value);
    }
}
