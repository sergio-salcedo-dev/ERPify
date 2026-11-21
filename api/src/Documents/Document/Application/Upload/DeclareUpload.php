<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use DateTimeImmutable;
use Erpify\Documents\Document\Application\Upload\Command\DeclareUploadCommand;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Documents\Document\Domain\Exception\DeclaredSizeOutOfRange;
use Erpify\Documents\Document\Domain\Exception\IdempotencyKeyConflict;
use Erpify\Documents\Document\Domain\Exception\PredecessorNotReady;
use Erpify\Documents\Document\Domain\Exception\UploadCapacityExhausted;
use Erpify\Documents\Document\Domain\Exception\UploadInProgress;
use Erpify\Documents\Document\Domain\Exception\VersionSlotTaken;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Upload\DocumentScopeId;
use Erpify\Documents\Document\Domain\Upload\LegalBasis;
use Erpify\Documents\Document\Domain\Upload\RetentionReason;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Documents\Document\Domain\Versioning\DocumentVersionSlots;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use LogicException;

/**
 * Step 1 of an evidence upload: records what the caller promises about the original — size, digest, retention
 * terms — as an upload intent, before a single byte is sent. Nothing here touches the keystore: the scope id is
 * minted at random and no key exists until the PUT seals the first chunk, so a refused or abandoned declare
 * leaves no tombstone.
 *
 * **Idempotency.** With a key, the caller's actor-scoped hash is what a replay is found by, and a replay is
 * compared on every declared parameter: equal answers what the first declare produced (by the state of its
 * intent), different is a conflict — even against an erased original, whose tombstone keeps exactly the
 * fields compared. A replay never streams twice. The insert uses the database's own conflict resolution on the
 * key, then re-reads the document table in a separate statement of the same transaction: a document that holds
 * the key rolls the insert back and answers instead. That re-read is what covers the window after the intent's
 * retention sweep, when only the document still holds the key.
 *
 * **Versions.** A new version is the same declare naming a predecessor: the intent records it, a replay compares it
 * like every other declared parameter, and an obviously refused successor is answered here before a byte is sent.
 *
 * **Caps.** The per-actor rate limit is the HTTP edge's; the cap on live `Declared` intents per actor is this
 * use case's, counted under a per-actor advisory lock so two declares cannot both see room for one.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") one exception per answer a replay gives, and two stores
 */
final readonly class DeclareUpload
{
    public function __construct(
        private UploadIntentRepository $intents,
        private DocumentRepository $documents,
        private IdempotencyKeyHasher $keyHasher,
        private TransactionManager $transactionManager,
        private DocumentVersionSlots $slots,
    ) {
    }

    /**
     * @throws DeclaredSizeOutOfRange  when the size is zero or above the cap
     * @throws IdempotencyKeyConflict  when the key was used with different parameters
     * @throws UploadInProgress        when the key's upload is streaming right now
     * @throws UploadCapacityExhausted when the actor holds too many live declared intents
     */
    public function declare(DeclareUploadCommand $command, string $actorId): DeclareUploadOutcome
    {
        return $this->declareFor($command, $actorId, null);
    }

    /**
     * Declares a new version of `$predecessorId`: the same declare, with the predecessor recorded on the intent and
     * compared on a replay. A new intent is refused at once when the predecessor is absent or not `Ready`, or when a
     * live version already holds its slot, so a caller does not stream 100 MiB to be told so at the commit; both are
     * read without a lock and only advisory, since the commit decides again under the series lock.
     *
     * @throws DeclaredSizeOutOfRange  when the size is zero or above the cap
     * @throws IdempotencyKeyConflict  when the key was used with different parameters
     * @throws UploadInProgress        when the key's upload is streaming right now
     * @throws UploadCapacityExhausted when the actor holds too many live declared intents
     * @throws PredecessorNotReady     when the predecessor is absent or not `Ready`
     * @throws VersionSlotTaken        when a live version already succeeds the predecessor
     */
    public function declareVersion(
        DeclareUploadCommand $command,
        string $actorId,
        string $predecessorId,
    ): DeclareUploadOutcome {
        Uuid::ensure($predecessorId);

        return $this->declareFor($command, $actorId, \strtolower($predecessorId));
    }

    private function declareFor(
        DeclareUploadCommand $command,
        string $actorId,
        ?string $predecessorId,
    ): DeclareUploadOutcome {
        $size = $command->size;

        if (null === $size || $size < 1 || $size > UploadLimits::MAX_ORIGINAL_BYTES) {
            throw DeclaredSizeOutOfRange::create();
        }

        if (!$command->retentionReason instanceof RetentionReason || !$command->legalBasis instanceof LegalBasis) {
            throw new LogicException('The retention terms are validated at the edge before a declare runs.');
        }

        Uuid::ensure($actorId);
        $actor = \strtolower($actorId);
        $sha256 = $command->normalisedSha256();
        $keyHash = null === $command->idempotencyKey ? null : $this->keyHasher->hash($actor, $command->idempotencyKey);

        $declaration = new Declaration(
            $actor,
            $size,
            $sha256,
            $keyHash,
            $command->retentionReason,
            $command->legalBasis,
            $command->normalisedReviewAt(),
            $predecessorId,
        );

        try {
            return $this->attempt($declaration);
        } catch (ConflictingIntentVanished) {
            return $this->attempt($declaration);
        }
    }

    private function attempt(Declaration $declaration): DeclareUploadOutcome
    {
        try {
            return $this->transactionManager->transactional(
                fn (): DeclareUploadOutcome => $this->declareWithin($declaration),
            );
        } catch (DocumentHoldsKey $documentHoldsKey) {
            return $this->answerDocument($documentHoldsKey->document, $declaration, null);
        }
    }

    private function declareWithin(Declaration $declaration): DeclareUploadOutcome
    {
        $this->intents->lockDeclareSlot($declaration->actorId);
        $now = $this->intents->now();

        if (null !== $declaration->keyHash) {
            $replay = $this->replayFor($declaration, $now);

            if ($replay instanceof DeclareUploadOutcome) {
                return $replay;
            }
        }

        $liveDeclared = $this->intents->countLiveDeclaredFor($declaration->actorId, $now);

        if ($liveDeclared >= UploadLimits::MAX_LIVE_DECLARED_PER_ACTOR) {
            throw UploadCapacityExhausted::create();
        }

        if (null !== $declaration->predecessorId) {
            $this->ensureSlotLooksFree($declaration->predecessorId);
        }

        $intent = UploadIntent::declare(
            Uuid::generate(),
            $declaration->actorId,
            DocumentScopeId::mint(),
            $declaration->size,
            $declaration->sha256,
            $declaration->keyHash,
            $declaration->retentionReason,
            $declaration->legalBasis,
            $declaration->reviewAt,
            $now,
            $declaration->predecessorId,
        );

        if (!$this->intents->insertUnlessKeyTaken($intent)) {
            // Lost to a concurrent declare of the same key that committed in between; answer what it holds.
            return $this->replayFor($declaration, $now) ?? throw new ConflictingIntentVanished();
        }

        if (null !== $declaration->keyHash) {
            $document = $this->documents->findByIdempotencyKeyHash($declaration->keyHash);

            if ($document instanceof Document) {
                throw new DocumentHoldsKey($document);
            }
        }

        return DeclareUploadOutcome::created($intent);
    }

    /**
     * What an earlier use of the key answers, or null when nothing holds it any more — including an intent that
     * had only expired by time, whose key hash is cleared here so the insert that follows can take the key.
     */
    private function replayFor(Declaration $declaration, DateTimeImmutable $now): ?DeclareUploadOutcome
    {
        \assert(null !== $declaration->keyHash);
        $existing = $this->intents->findByKeyHash($declaration->keyHash);

        if (!$existing instanceof UploadIntent) {
            $document = $this->documents->findByIdempotencyKeyHash($declaration->keyHash);

            return $document instanceof Document ? $this->answerDocument($document, $declaration, null) : null;
        }

        $intent = $this->intents->lockForUpdate($existing->id());

        if (!$intent instanceof UploadIntent) {
            return null;
        }

        return match ($intent->effectiveState($now)) {
            UploadIntentState::Committed => $this->answerCommitted($intent, $declaration),
            UploadIntentState::Declared => $this->matches($intent, $declaration)
                ? DeclareUploadOutcome::replayedIntent($intent)
                : throw IdempotencyKeyConflict::create(),
            UploadIntentState::Streaming => throw $this->matches($intent, $declaration)
                ? UploadInProgress::retryAfter($intent->secondsLeftOnLease($now))
                : IdempotencyKeyConflict::create(),
            UploadIntentState::Expired => $this->release($intent, $now),
        };
    }

    private function answerCommitted(UploadIntent $intent, Declaration $declaration): DeclareUploadOutcome
    {
        $documentId = $intent->documentId();
        $document = null === $documentId ? null : $this->documents->findById($documentId);

        if (!$document instanceof Document) {
            throw new LogicException('A committed upload intent names a document that does not exist.');
        }

        return $this->answerDocument($document, $declaration, $intent);
    }

    private function answerDocument(
        Document $document,
        Declaration $declaration,
        ?UploadIntent $intent,
    ): DeclareUploadOutcome {
        $same = $document->predecessorId() === $declaration->predecessorId
            && $document->sizeBytes() === $declaration->size
            && $document->digest() === $declaration->sha256
            && $document->retentionReason() === $declaration->retentionReason->value
            && $document->legalBasis() === $declaration->legalBasis->value
            && $document->reviewAt()?->getTimestamp() === $declaration->reviewAt?->getTimestamp();

        if (!$same) {
            throw IdempotencyKeyConflict::create();
        }

        return DeclareUploadOutcome::replayedDocument($document, $intent);
    }

    private function matches(UploadIntent $intent, Declaration $declaration): bool
    {
        return $intent->matchesDeclaration(
            $declaration->size,
            $declaration->sha256,
            $declaration->retentionReason,
            $declaration->legalBasis,
            $declaration->reviewAt,
            $declaration->predecessorId,
        );
    }

    /**
     * The new-version declare's early refusal, read without a lock: an absent predecessor, one that is not `Ready` and
     * one in a series whose erasure has begun answer alike, and a live successor already below it answers the slot
     * conflict. The commit decides all of it again under the series lock.
     */
    private function ensureSlotLooksFree(string $predecessorId): void
    {
        $predecessor = $this->documents->findById($predecessorId);

        if (!$predecessor instanceof Document || DocumentState::Ready !== $predecessor->state()) {
            throw PredecessorNotReady::create();
        }

        if ($this->slots->isSeriesClosed($predecessor->seriesId())) {
            throw PredecessorNotReady::create();
        }

        if ($this->slots->isSlotHeld($predecessorId)) {
            throw VersionSlotTaken::create();
        }
    }

    /**
     * An intent that expired by time still holds its key hash until something stamps it; stamping it here
     * releases the key for this declare, and leaves the intent to the reaper like any other expired one.
     */
    private function release(UploadIntent $intent, DateTimeImmutable $now): null
    {
        $intent->expire($now);
        $this->intents->save($intent);

        return null;
    }
}
