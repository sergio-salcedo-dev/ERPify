<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Exception\DeclaredSizeOutOfRange;
use Erpify\Documents\Document\Domain\Upload\LegalBasis;
use Erpify\Documents\Document\Domain\Upload\RetentionReason;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use LogicException;

/**
 * The durable record that precedes every sealed byte of an evidence upload (`docs/adr/documents-custody-log.md`
 * D6). The declare step creates it `Declared`; the PUT claims it into `Streaming` before it reads a byte or
 * mints a key; one short transaction moves it to `Committed` once the document row exists. It is the one lock
 * the commit and the reaper share, so every decision about an upload in flight is taken under this row.
 *
 * **Time is the database's.** Every method that judges a lease, a deadline or a TTL takes `$now`, and its
 * callers read it from PostgreSQL (`NOW()`) inside the transaction that holds the row: two PHP threads, a
 * worker and the scheduler disagree about the wall clock, the database does not.
 *
 * **What it never holds.** The original filename travels in the PUT and is sealed in the commit transaction;
 * this row never sees it. The key hash names the caller's idempotency key only through an actor-scoped HMAC,
 * and it is replaced with NULL on every transition to `Expired` and by the person purge, so an expired intent
 * never blocks its key and an erased actor's replay protection ends.
 *
 * The scope id is minted here, at random, and is the document's own from this moment: the staging key embeds
 * it, so promotion needs no re-seal, and it carries a `UNIQUE` because two uploads sharing a scope would let
 * one's tombstone shred the other's bytes.
 *
 * @SuppressWarnings("PHPMD.TooManyFields") one row per upload, every column a fact the state machine reads
 * @SuppressWarnings("PHPMD.ExcessivePublicCount") the accessors of one snapshot
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the accessors of one snapshot
 * @SuppressWarnings("PHPMD.TooManyMethods") the accessors of one snapshot and its five transitions
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") each transition judges time against the same three bounds
 */
#[ORM\Entity]
#[ORM\Table(name: 'upload_intent')]
#[ORM\UniqueConstraint(name: self::SCOPE_ID_CONSTRAINT, columns: ['scope_id'])]
#[ORM\UniqueConstraint(name: self::KEY_HASH_CONSTRAINT, columns: ['key_hash'])]
#[ORM\Index(name: 'idx_upload_intent_actor_id', columns: ['actor_id'])]
#[ORM\Index(name: 'idx_upload_intent_state', columns: ['state'])]
final class UploadIntent
{
    private const string MODIFY_SECONDS = '+%d seconds';

    public const string SCOPE_ID_CONSTRAINT = 'uniq_upload_intent_scope_id';

    public const string KEY_HASH_CONSTRAINT = 'uniq_upload_intent_key_hash';

    #[ORM\Column(name: 'document_id', type: Types::GUID, nullable: true)]
    private ?string $documentId = null;

    // Written NULL until versioning ships: the declare step has no predecessor field, so no successor is filed.
    #[ORM\Column(name: 'predecessor_id', type: Types::GUID, nullable: true, updatable: false)]
    // @phpstan-ignore property.unusedType (Doctrine hydrates a successor's id once versioning exists)
    private ?string $predecessorId = null;

    #[ORM\Column(length: 16, enumType: UploadIntentState::class)]
    private UploadIntentState $state = UploadIntentState::Declared;

    #[ORM\Column(name: 'key_hash', length: 64, nullable: true)]
    private ?string $keyHash;

    #[ORM\Column(name: 'ttl_expires_at', type: Types::DATETIMETZ_IMMUTABLE, updatable: false)]
    private DateTimeImmutable $ttlExpiresAt;

    #[ORM\Column(name: 'claimed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $claimedAt = null;

    #[ORM\Column(name: 'lease_expires_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $leaseExpiresAt = null;

    #[ORM\Column(name: 'upload_deadline_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $uploadDeadlineAt = null;

    #[ORM\Column(name: 'committed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $committedAt = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\Column(name: 'actor_id', type: Types::GUID)]
        #[PersonSubjectReference(erasedBy: 'src/Documents/Document/Application/PurgeUserDocumentReferences.php')]
        private string $actorId,
        #[ORM\Column(name: 'scope_id', type: Types::GUID, updatable: false)]
        private string $scopeId,
        #[ORM\Column(name: 'declared_size', type: Types::INTEGER, updatable: false)]
        private int $declaredSize,
        #[ORM\Column(name: 'declared_sha256', length: 64, nullable: true, updatable: false)]
        private ?string $declaredSha256,
        ?string $keyHash,
        #[ORM\Column(name: 'retention_reason', length: 32, updatable: false, enumType: RetentionReason::class)]
        private RetentionReason $retentionReason,
        #[ORM\Column(name: 'legal_basis', length: 32, updatable: false, enumType: LegalBasis::class)]
        private LegalBasis $legalBasis,
        #[ORM\Column(name: 'review_at', type: Types::DATETIME_IMMUTABLE, nullable: true, updatable: false)]
        private ?DateTimeImmutable $reviewAt,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, updatable: false)]
        private DateTimeImmutable $createdAt,
    ) {
        $this->keyHash = $keyHash;
        $this->ttlExpiresAt = $this->createdAt->modify(
            \sprintf(self::MODIFY_SECONDS, UploadLimits::DECLARED_TTL_SECONDS),
        );
    }

    /**
     * @throws DeclaredSizeOutOfRange when the size is zero, negative or above the cap
     */
    public static function declare(
        string $id,
        string $actorId,
        string $scopeId,
        int $declaredSize,
        ?string $declaredSha256,
        ?string $keyHash,
        RetentionReason $retentionReason,
        LegalBasis $legalBasis,
        ?DateTimeImmutable $reviewAt,
        DateTimeImmutable $now,
    ): self {
        if ($declaredSize < 1 || $declaredSize > UploadLimits::MAX_ORIGINAL_BYTES) {
            throw DeclaredSizeOutOfRange::create();
        }

        self::ensureIdentifiers($id, $actorId, $scopeId);
        self::ensureDigests($declaredSha256, $keyHash);

        return new self(
            $id,
            $actorId,
            $scopeId,
            $declaredSize,
            $declaredSha256,
            $keyHash,
            $retentionReason,
            $legalBasis,
            $reviewAt,
            $now,
        );
    }

    /**
     * The state the intent is in once time is taken into account: a `Declared` intent past its TTL and a
     * `Streaming` one past its lease or its deadline count as `Expired` before anything stamps them so.
     */
    public function effectiveState(DateTimeImmutable $now): UploadIntentState
    {
        return match ($this->state) {
            UploadIntentState::Declared => $this->ttlExpiresAt > $now
                ? UploadIntentState::Declared
                : UploadIntentState::Expired,
            UploadIntentState::Streaming => $this->streamingIsLive($now)
                ? UploadIntentState::Streaming
                : UploadIntentState::Expired,
            default => $this->state,
        };
    }

    /**
     * `Declared` to `Streaming`, before any byte is read or any key minted. The deadline is fixed here, from
     * the declared size at the minimum accepted throughput, and nothing extends it.
     */
    public function claim(DateTimeImmutable $now): void
    {
        if (UploadIntentState::Declared !== $this->effectiveState($now)) {
            throw new LogicException('Only a live declared upload can be claimed.');
        }

        $this->state = UploadIntentState::Streaming;
        $this->claimedAt = $now;
        $this->uploadDeadlineAt = $now->modify(
            \sprintf(self::MODIFY_SECONDS, UploadLimits::deadlineSecondsFor($this->declaredSize)),
        );
        $this->leaseExpiresAt = $this->leaseFrom($now);
    }

    /**
     * Renews the lease of a live stream, never beyond the deadline. False when the stream is no longer live —
     * its lease or deadline passed, or something expired it — and the writer must abort.
     */
    public function renewLease(DateTimeImmutable $now): bool
    {
        if (UploadIntentState::Streaming !== $this->effectiveState($now)) {
            return false;
        }

        $this->leaseExpiresAt = $this->leaseFrom($now);

        return true;
    }

    /**
     * `Streaming` to `Committed` with the document that now holds the scope. Requires the lease and the
     * deadline to be live under the lock, which is what lets the reaper and the commit agree on one outcome.
     */
    public function commit(string $documentId, DateTimeImmutable $now): void
    {
        if (UploadIntentState::Streaming !== $this->effectiveState($now)) {
            throw new LogicException('Only a live streaming upload can be committed.');
        }

        Uuid::ensure($documentId);

        $this->state = UploadIntentState::Committed;
        $this->documentId = $documentId;
        $this->committedAt = $now;
    }

    /**
     * Any live state to `Expired`: the key hash goes, so the key can be declared again, and the lease is set to
     * now so the reaper can act at once. Idempotent on an expired intent; a committed one is refused, since its
     * document exists and only the retention sweep removes it.
     */
    public function expire(DateTimeImmutable $now): void
    {
        if (UploadIntentState::Committed === $this->state) {
            throw new LogicException('A committed upload does not expire; its retention sweep removes it.');
        }

        if (UploadIntentState::Expired === $this->state) {
            return;
        }

        $this->state = UploadIntentState::Expired;
        $this->keyHash = null;
        $this->leaseExpiresAt = $now;
    }

    /**
     * Whether a replay repeats this declaration exactly. Every declared parameter takes part, so a different one
     * is a conflict rather than a silent answer with the earlier upload.
     */
    public function matchesDeclaration(
        int $declaredSize,
        ?string $declaredSha256,
        RetentionReason $retentionReason,
        LegalBasis $legalBasis,
        ?DateTimeImmutable $reviewAt,
    ): bool {
        return $this->declaredSize === $declaredSize
            && $this->declaredSha256 === $declaredSha256
            && $this->retentionReason === $retentionReason
            && $this->legalBasis === $legalBasis
            && $reviewAt?->getTimestamp() === $this->reviewAt?->getTimestamp();
    }

    public function secondsLeftOnLease(DateTimeImmutable $now): int
    {
        if (!$this->leaseExpiresAt instanceof DateTimeImmutable) {
            return 0;
        }

        return \max(0, $this->leaseExpiresAt->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Whether the reaper may act: never on a committed intent, and on any other only once it counts as expired.
     */
    public function isReapable(DateTimeImmutable $now): bool
    {
        return UploadIntentState::Committed !== $this->state
            && UploadIntentState::Expired === $this->effectiveState($now);
    }

    /** A claimed intent may have minted a key, whatever state it has been moved to since. */
    public function wasClaimed(): bool
    {
        return $this->claimedAt instanceof DateTimeImmutable;
    }

    public function isHeldBy(string $actorId): bool
    {
        return \strtolower($actorId) === $this->actorId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function actorId(): string
    {
        return $this->actorId;
    }

    public function scopeId(): string
    {
        return $this->scopeId;
    }

    public function documentId(): ?string
    {
        return $this->documentId;
    }

    public function predecessorId(): ?string
    {
        return $this->predecessorId;
    }

    public function state(): UploadIntentState
    {
        return $this->state;
    }

    public function declaredSize(): int
    {
        return $this->declaredSize;
    }

    public function declaredSha256(): ?string
    {
        return $this->declaredSha256;
    }

    public function keyHash(): ?string
    {
        return $this->keyHash;
    }

    public function retentionReason(): RetentionReason
    {
        return $this->retentionReason;
    }

    public function legalBasis(): LegalBasis
    {
        return $this->legalBasis;
    }

    public function reviewAt(): ?DateTimeImmutable
    {
        return $this->reviewAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function ttlExpiresAt(): DateTimeImmutable
    {
        return $this->ttlExpiresAt;
    }

    public function claimedAt(): ?DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function leaseExpiresAt(): ?DateTimeImmutable
    {
        return $this->leaseExpiresAt;
    }

    public function uploadDeadlineAt(): ?DateTimeImmutable
    {
        return $this->uploadDeadlineAt;
    }

    public function committedAt(): ?DateTimeImmutable
    {
        return $this->committedAt;
    }

    private static function ensureIdentifiers(string $id, string $actorId, string $scopeId): void
    {
        foreach ([$id, $actorId, $scopeId] as $identifier) {
            Uuid::ensure($identifier);

            if (\strtolower($identifier) !== $identifier) {
                throw new InvalidArgumentException('Identifiers must be lower-case UUIDs.');
            }
        }

        if (ErasedPersonMarker::UUID === $actorId) {
            throw new InvalidArgumentException('The erased-person marker is written by the erasure, never declared.');
        }
    }

    private static function ensureDigests(?string $declaredSha256, ?string $keyHash): void
    {
        foreach ([$declaredSha256, $keyHash] as $digest) {
            if (null !== $digest && 1 !== \preg_match('/^[0-9a-f]{64}$/D', $digest)) {
                throw new InvalidArgumentException('Digests are 64-character lower-case hexadecimal strings.');
            }
        }

        if (null !== $keyHash && null === $declaredSha256) {
            throw new InvalidArgumentException('An idempotency key requires a declared sha256.');
        }
    }

    private function streamingIsLive(DateTimeImmutable $now): bool
    {
        return $this->leaseExpiresAt instanceof DateTimeImmutable
            && $this->uploadDeadlineAt instanceof DateTimeImmutable
            && $this->leaseExpiresAt > $now
            && $this->uploadDeadlineAt > $now;
    }

    private function leaseFrom(DateTimeImmutable $now): DateTimeImmutable
    {
        $lease = $now->modify(\sprintf(self::MODIFY_SECONDS, UploadLimits::LEASE_SECONDS));

        if ($this->uploadDeadlineAt instanceof DateTimeImmutable && $lease > $this->uploadDeadlineAt) {
            return $this->uploadDeadlineAt;
        }

        return $lease;
    }
}
