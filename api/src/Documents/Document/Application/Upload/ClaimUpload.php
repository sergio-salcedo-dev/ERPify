<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use DateTimeImmutable;
use Erpify\Documents\Document\Application\Upload\Command\UploadEvidenceCommand;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Documents\Document\Domain\Exception\ContentLengthMismatch;
use Erpify\Documents\Document\Domain\Exception\ContentLengthRequired;
use Erpify\Documents\Document\Domain\Exception\InvalidOriginalFilename;
use Erpify\Documents\Document\Domain\Exception\UploadCapacityExhausted;
use Erpify\Documents\Document\Domain\Exception\UploadGone;
use Erpify\Documents\Document\Domain\Exception\UploadInProgress;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use LogicException;

/**
 * The PUT's first transaction: under the intent row's lock it authorises the caller as the intent's actor,
 * dispatches on the intent's state and, only on a live `Declared` one, checks everything it can before moving
 * it to `Streaming` — so a request that fails here leaves the intent `Declared` for a corrected retry.
 *
 * Order of the checks on a `Declared` intent: `Content-Length` (present, then equal to the declared size), then
 * the filename (exactly one `filename` parameter, then the value's own rules), then the streaming caps. The caps
 * are counted under transaction-scoped advisory locks, per actor then global, taken after the intent row and
 * with nothing else acquired while they are held; only `Streaming` intents whose lease and deadline are both
 * live count, so a dead claim never holds a slot whatever the reaper's cadence.
 *
 * Every other state answers without touching anything: `Committed` with its document (ignoring any filename,
 * so a lost response is recoverable whatever the retry carries), `Streaming` with a conflict carrying the
 * remaining lease, and an expired intent — or one that is absent or someone else's, indistinguishably — with
 * the gone problem type.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") one exception per refusal the claim can answer
 */
final readonly class ClaimUpload
{
    public function __construct(
        private UploadIntentRepository $intents,
        private DocumentRepository $documents,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws InvalidUuidException    when the upload id is not a UUID
     * @throws UploadGone              when the intent is absent, someone else's or expired
     * @throws UploadInProgress        when another request is streaming it
     * @throws ContentLengthRequired   when the PUT declares no length
     * @throws ContentLengthMismatch   when the declared length is not the declared size
     * @throws InvalidOriginalFilename when the filename is missing, repeated or breaks a rule
     * @throws UploadCapacityExhausted when a streaming cap is reached
     */
    public function claim(UploadEvidenceCommand $command): ClaimedUpload|Document
    {
        Uuid::ensure($command->uploadId);

        return $this->transactionManager->transactional(
            fn (): ClaimedUpload|Document => $this->claimWithin($command),
        );
    }

    private function claimWithin(UploadEvidenceCommand $command): ClaimedUpload|Document
    {
        $intent = $this->intents->lockForUpdate(\strtolower($command->uploadId));

        if (!$intent instanceof UploadIntent || !$intent->isHeldBy($command->actorId)) {
            throw UploadGone::create();
        }

        $now = $this->intents->now();

        return match ($intent->effectiveState($now)) {
            UploadIntentState::Committed => $this->committedDocument($intent),
            UploadIntentState::Streaming => throw UploadInProgress::retryAfter($intent->secondsLeftOnLease($now)),
            UploadIntentState::Expired => throw UploadGone::create(),
            UploadIntentState::Declared => $this->claimDeclared($intent, $command, $now),
        };
    }

    private function claimDeclared(
        UploadIntent $intent,
        UploadEvidenceCommand $command,
        DateTimeImmutable $now,
    ): ClaimedUpload {
        if (null === $command->contentLength) {
            throw ContentLengthRequired::create();
        }

        if ($command->contentLength !== $intent->declaredSize()) {
            throw ContentLengthMismatch::create();
        }

        $original = $command->filename->filenameOrRefuse();

        $this->intents->lockClaimSlots($intent->actorId());

        if (
            $this->intents->countLiveStreamingFor($intent->actorId(), $now) >= UploadLimits::MAX_STREAMING_PER_ACTOR
            || $this->intents->countLiveStreaming($now) >= UploadLimits::MAX_STREAMING_GLOBAL
        ) {
            throw UploadCapacityExhausted::create();
        }

        $intent->claim($now);
        $this->intents->save($intent);

        return new ClaimedUpload(
            $intent->id(),
            $intent->scopeId(),
            $intent->declaredSize(),
            $intent->declaredSha256(),
            $original,
        );
    }

    private function committedDocument(UploadIntent $intent): Document
    {
        $documentId = $intent->documentId();
        $document = null === $documentId ? null : $this->documents->findById($documentId);

        if (!$document instanceof Document) {
            throw new LogicException('A committed upload intent names a document that does not exist.');
        }

        return $document;
    }
}
