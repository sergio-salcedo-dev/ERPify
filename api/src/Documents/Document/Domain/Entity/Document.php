<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Documents\Document\Domain\DocumentIdentifiers;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Exception\DocumentNotReadable;
use Erpify\Documents\Document\Domain\Exception\PredecessorNotReady;
use Erpify\Documents\Document\Domain\IntakeMetadata;
use Erpify\Documents\Document\Domain\OriginalContent;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use InvalidArgumentException;

/**
 * The current snapshot of one evidence-class binary. State-oriented on purpose: the business needs where the
 * document stands now, while the history of how it got there lives in `document_custody`, appended in the
 * same transaction as the snapshot change (`docs/adr/documents-custody-log.md`).
 *
 * **Each version is its own aggregate.** `seriesId` links it to its series by a scalar and `predecessorId` to
 * the version it replaces. Both are written once and have no setter and no UPDATE path (`updatable: false`): a
 * first version is its own series with no predecessor ({@see receive()}), and a successor copies the series of the
 * predecessor it is handed, which the caller has locked and which must be `Ready` ({@see succeed()}). Whether the
 * predecessor's slot is free is not this aggregate's to know — it spans other versions — and is decided under the
 * series lock (`DocumentVersionSlots`). Ids are minted, never reused, and a predecessor exists before its successor,
 * so the links cannot close a cycle.
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
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the accessors of one snapshot and its one guard
 * @SuppressWarnings("PHPMD.TooManyMethods") the same
 * @SuppressWarnings("PHPMD.TooManyFields") one row: the document, its intake and the scan lease columns of D8
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") the same row, with the erased-object marker beside the scan columns
 */
#[ORM\Entity]
#[ORM\Table(name: 'document')]
#[ORM\UniqueConstraint(name: self::SCOPE_ID_CONSTRAINT, columns: ['scope_id'])]
#[ORM\UniqueConstraint(name: self::IDEMPOTENCY_KEY_CONSTRAINT, columns: ['idempotency_key_hash'])]
#[ORM\Index(name: 'idx_document_uploader_id', columns: ['uploader_id'])]
// The scan sweep's candidate list: only promoted `Received` rows are ever due, and they are few next to the table.
// The predicate is spelled as PostgreSQL prints it back (`pg_get_expr`), because the schema comparison matches it as
// text: the migration writes the plain form and the database stores this one.
#[ORM\Index(
    name: 'idx_document_scan_due',
    columns: ['next_scan_at'],
    options: ['where' => "(((state)::text = 'received'::text) AND (promoted_at IS NOT NULL))"],
)]
// The object-deletion sweep's candidate list: only `Erased` rows whose sealed object is not yet confirmed deleted.
#[ORM\Index(
    name: 'idx_document_erased_object_pending',
    columns: ['id'],
    options: ['where' => "(((state)::text = 'erased'::text) AND (object_deleted_at IS NULL))"],
)]
// The derivative release sweep's candidate list: only `Erased` rows whose derivatives are not yet confirmed released.
#[ORM\Index(
    name: 'idx_document_erased_derivatives_pending',
    columns: ['id'],
    options: ['where' => "(((state)::text = 'erased'::text) AND (derivatives_released_at IS NULL))"],
)]
final class Document extends AggregateRoot
{
    /**
     * Constraint names are explicit because a unique violation is classified by NAME: the idempotency key
     * is a conflict, never retried, and must not be confused with the custody sequence constraint, which is.
     */
    public const string SCOPE_ID_CONSTRAINT = 'uniq_document_scope_id';

    public const string IDEMPOTENCY_KEY_CONSTRAINT = 'uniq_document_idempotency_key_hash';

    private const string NEXT_SCAN_AT_FALLBACK = '1970-01-01 00:00:00+00';

    #[ORM\Column(name: 'scope_id', type: Types::GUID, updatable: false)]
    private string $scopeId;

    #[ORM\Column(name: 'series_id', type: Types::GUID, updatable: false)]
    private string $seriesId;

    #[ORM\Column(name: 'predecessor_id', type: Types::GUID, nullable: true, updatable: false)]
    private ?string $predecessorId;

    #[ORM\Column(name: 'uploader_id', type: Types::GUID)]
    #[PersonSubjectReference(erasedBy: 'src/Documents/Document/Application/PurgeUserDocumentReferences.php')]
    private string $uploaderId;

    #[ORM\Column(length: 16, enumType: DocumentState::class)]
    private DocumentState $state = DocumentState::Received;

    #[ORM\Column(length: OriginalContent::DIGEST_HEX_LENGTH, updatable: false)]
    private string $digest;

    #[ORM\Column(name: 'size_bytes', type: Types::INTEGER, updatable: false)]
    private int $sizeBytes;

    #[ORM\Column(name: 'idempotency_key_hash', length: OriginalContent::DIGEST_HEX_LENGTH, nullable: true)]
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

    /**
     * Set by the object-deletion sweep once neither the staged nor the final object of an `Erased` row exists, so the
     * sweep does not probe every erased row for ever. Read-only to the ORM like `promoted_at`: a compare-and-set
     * statement writes it (`DbalDocumentErasures`), and no custody row records it.
     */
    #[ORM\Column(
        name: 'object_deleted_at',
        type: Types::DATETIMETZ_IMMUTABLE,
        nullable: true,
        insertable: false,
        updatable: false,
    )]
    // @phpstan-ignore property.unusedType (Doctrine hydrates it; a compare-and-set statement writes it)
    private ?DateTimeImmutable $objectDeletedAt = null;

    /**
     * Set by the derivative release sweep once a re-query by this document's origin finds no image left, so the sweep
     * does not ask about every erased row for ever. A confirmed absence, never "requests queued". Read-only to the ORM
     * like `object_deleted_at`: a compare-and-set statement writes it (`DbalDocumentErasures`).
     */
    #[ORM\Column(
        name: 'derivatives_released_at',
        type: Types::DATETIMETZ_IMMUTABLE,
        nullable: true,
        insertable: false,
        updatable: false,
    )]
    // @phpstan-ignore property.unusedType (Doctrine hydrates it; a compare-and-set statement writes it)
    private ?DateTimeImmutable $derivativesReleasedAt = null;

    /**
     * When the release sweep last queued the deletion of the row's derivatives, so it asks again only once the
     * transport's retries are over. Read-only to the ORM like the markers above.
     */
    #[ORM\Column(
        name: 'derivatives_requested_at',
        type: Types::DATETIMETZ_IMMUTABLE,
        nullable: true,
        insertable: false,
        updatable: false,
    )]
    // @phpstan-ignore property.unusedType (Doctrine hydrates it; a statement writes it)
    private ?DateTimeImmutable $derivativesRequestedAt = null;

    /**
     * When a restore replay last reopened the row's erasure markers. Both erasure alarms measure their bound from the
     * later of this and the `Erased` custody row, so a restore does not turn every erased row it reopened into an
     * overdue one before the sweeps have had their turn. Read-only to the ORM like the markers above.
     */
    #[ORM\Column(
        name: 'erasure_reopened_at',
        type: Types::DATETIMETZ_IMMUTABLE,
        nullable: true,
        insertable: false,
        updatable: false,
    )]
    // @phpstan-ignore property.unusedType (Doctrine hydrates it; a statement writes it)
    private ?DateTimeImmutable $erasureReopenedAt = null;

    /**
     * The scan's own columns. Like `promoted_at` they are read-only to the ORM: every transition is a DBAL
     * compare-and-set (`DbalDocumentScanLeases`) evaluated by the database clock, so no managed instance is ever
     * the writer and a stale one cannot overwrite a newer lease.
     *
     * `scan_attempts` counts claims that began streaming and ended with no verdict; `scan_outage_returns` counts
     * the returns that were the scanner's absence. `lease_epoch` only grows (every claim and every operator reset).
     */
    #[ORM\Column(
        name: 'scan_attempts',
        type: Types::INTEGER,
        insertable: false,
        updatable: false,
        options: ['default' => 0],
    )]
    private int $scanAttempts = 0;

    #[ORM\Column(
        name: 'scan_outage_returns',
        type: Types::INTEGER,
        insertable: false,
        updatable: false,
        options: ['default' => 0],
    )]
    private int $scanOutageReturns = 0;

    #[ORM\Column(
        name: 'lease_epoch',
        type: Types::BIGINT,
        insertable: false,
        updatable: false,
        options: ['default' => 0],
    )]
    // @phpstan-ignore property.unusedType (Doctrine hydrates a bigint as a string)
    private int|string $leaseEpoch = 0;

    #[ORM\Column(
        name: 'first_claim_recorded',
        type: Types::BOOLEAN,
        insertable: false,
        updatable: false,
        options: ['default' => false],
    )]
    private bool $firstClaimRecorded = false;

    #[ORM\Column(
        name: 'scan_streaming_started_at',
        type: Types::DATETIMETZ_IMMUTABLE,
        nullable: true,
        insertable: false,
        updatable: false,
    )]
    // @phpstan-ignore property.unusedType (Doctrine hydrates it; a compare-and-set statement writes it)
    private ?DateTimeImmutable $scanStreamingStartedAt = null;

    #[ORM\Column(
        name: 'claimed_at',
        type: Types::DATETIMETZ_IMMUTABLE,
        nullable: true,
        insertable: false,
        updatable: false,
    )]
    // @phpstan-ignore property.unusedType (Doctrine hydrates it; a compare-and-set statement writes it)
    private ?DateTimeImmutable $claimedAt = null;

    #[ORM\Column(
        name: 'lease_expires_at',
        type: Types::DATETIMETZ_IMMUTABLE,
        nullable: true,
        insertable: false,
        updatable: false,
    )]
    // @phpstan-ignore property.unusedType (Doctrine hydrates it; a compare-and-set statement writes it)
    private ?DateTimeImmutable $leaseExpiresAt = null;

    private function __construct(
        DocumentIdentifiers $identifiers,
        OriginalContent $content,
        ?string $idempotencyKeyHash,
        IntakeMetadata $intake,
        ?self $predecessor,
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

        $this->ensureIdempotencyKeyHash($idempotencyKeyHash);

        $this->id = $identifiers->id;
        $this->scopeId = $identifiers->scopeId;
        $this->seriesId = $predecessor instanceof self ? $predecessor->seriesId : $identifiers->id;
        $this->predecessorId = $predecessor?->documentId();
        $this->uploaderId = $identifiers->uploaderId;
        $this->digest = $content->digest;
        $this->sizeBytes = $content->sizeBytes;
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
        DocumentIdentifiers $identifiers,
        OriginalContent $content,
        ?string $idempotencyKeyHash = null,
        ?IntakeMetadata $intake = null,
        ?DateTimeImmutable $receivedAt = null,
    ): self {
        return new self(
            $identifiers,
            $content,
            $idempotencyKeyHash,
            $intake ?? new IntakeMetadata(),
            null,
            $receivedAt ?? SystemClock::now(),
        );
    }

    /**
     * Files a new version of the predecessor's series in `Received`, linked to it. The predecessor is the row the
     * caller loaded under its lock, so the series is copied from the database, never from a caller; it must be
     * `Ready`, because a version that is not yet readable, or no longer is, has nothing a successor could replace.
     *
     * @throws PredecessorNotReady when the predecessor is in any state but `Ready`
     */
    public static function succeed(
        self $predecessor,
        DocumentIdentifiers $identifiers,
        OriginalContent $content,
        ?string $idempotencyKeyHash,
        IntakeMetadata $intake,
        DateTimeImmutable $receivedAt,
    ): self {
        if (DocumentState::Ready !== $predecessor->state) {
            throw PredecessorNotReady::create();
        }

        if ($predecessor->documentId() === $identifiers->id) {
            throw new InvalidArgumentException('A version cannot succeed itself.');
        }

        return new self(
            $identifiers,
            $content,
            $idempotencyKeyHash,
            $intake,
            $predecessor,
            $receivedAt,
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

    public function objectDeletedAt(): ?DateTimeImmutable
    {
        return $this->objectDeletedAt;
    }

    public function derivativesReleasedAt(): ?DateTimeImmutable
    {
        return $this->derivativesReleasedAt;
    }

    public function derivativesRequestedAt(): ?DateTimeImmutable
    {
        return $this->derivativesRequestedAt;
    }

    public function erasureReopenedAt(): ?DateTimeImmutable
    {
        return $this->erasureReopenedAt;
    }

    public function nextScanAt(): DateTimeImmutable
    {
        return $this->nextScanAt;
    }

    public function scanAttempts(): int
    {
        return $this->scanAttempts;
    }

    public function scanOutageReturns(): int
    {
        return $this->scanOutageReturns;
    }

    public function leaseEpoch(): int
    {
        return (int) $this->leaseEpoch;
    }

    public function firstClaimRecorded(): bool
    {
        return $this->firstClaimRecorded;
    }

    public function scanStreamingStartedAt(): ?DateTimeImmutable
    {
        return $this->scanStreamingStartedAt;
    }

    public function claimedAt(): ?DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function leaseExpiresAt(): ?DateTimeImmutable
    {
        return $this->leaseExpiresAt;
    }

    /**
     * The one readability guard: content is reachable only in `Ready`. Every consumer calls this and mints no
     * refusal of its own, so a state added later is closed by default.
     *
     * @throws DocumentNotReadable in every other state, `Erased` included
     */
    public function assertReadable(): void
    {
        if (DocumentState::Ready !== $this->state) {
            throw DocumentNotReadable::create();
        }
    }

    private function ensureIdempotencyKeyHash(?string $idempotencyKeyHash): void
    {
        if (null !== $idempotencyKeyHash && !OriginalContent::isSha256Hex($idempotencyKeyHash)) {
            throw new InvalidArgumentException(
                'The idempotency key hash must be a 64-character lower-case hexadecimal string.',
            );
        }
    }
}
