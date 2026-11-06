<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Application\Upload\Command\UploadEvidenceCommand;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Documents\Document\Domain\Exception\ContentLengthMismatch;
use Erpify\Documents\Document\Domain\Exception\ContentLengthRequired;
use Erpify\Documents\Document\Domain\Exception\InvalidOriginalFilename;
use Erpify\Documents\Document\Domain\Exception\UploadAborted;
use Erpify\Documents\Document\Domain\Exception\UploadCapacityExhausted;
use Erpify\Documents\Document\Domain\Exception\UploadGone;
use Erpify\Documents\Document\Domain\Exception\UploadInProgress;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\CannotSealEmptyStream;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Domain\Exception\StreamTransferFailed;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Throwable;

/**
 * Step 2 of an evidence upload: the raw-body PUT. The second example of the Upload row of
 * `docs/rules/cqrs-naming.md`, beside `UploadImage`; the contract is chosen by the use case, never by the bytes.
 *
 * Four phases, and no database transaction spans the stream:
 *
 *  1. **Claim** ({@see ClaimUpload}) — one short transaction moves the intent `Declared` to `Streaming`, or
 *     answers without touching anything.
 *  2. **Stream** — the body is read through an {@see UploadMeter} (count, hash, heartbeat, first-extra-byte
 *     refusal) and sealed untransformed straight into the staging key. The key is minted by the sealer after the
 *     first chunk, outside any transaction, so it commits on its own.
 *  3. **Check and commit** — the counted size must equal the declared one and the computed digest any declared
 *     one; then {@see CommitUpload} files the `Received` document under the intent and keystore locks. The
 *     computed digest is what the row stores.
 *  4. **Promote** ({@see PromoteDocument}) — rename into the final namespace, sync, set `promoted_at`, wake the
 *     scan. A failure here is not the caller's: the document is filed, the roll-forward sweep finishes it.
 *
 * Any failure between the claim and the commit ends the upload: {@see ExpireUpload} removes the staged object
 * and expires the intent, and the reaper tombstones the scope. A body that is not the declared one, a lost
 * lease and a refused commit answer {@see UploadAborted}; a fault of the server's own (a sink that cannot be
 * written, a database error) propagates as itself, since the caller could not have avoided it.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the orchestrator of the four phases
 */
final readonly class UploadEvidence
{
    public const string ORIGINAL_ASSOCIATED_DATA = 'document-original';

    public function __construct(
        private ClaimUpload $claimUpload,
        private CommitUpload $commitUpload,
        private ExpireUpload $expireUpload,
        private PromoteDocument $promoteDocument,
        private StreamEnvelopeEncryptor $sealer,
        private DocumentStorage $storage,
        private UploadIntentRepository $intents,
        private TransactionManager $transactionManager,
        private UploadTimeBudget $timeBudget,
        private int $heartbeatIntervalSeconds = UploadLimits::HEARTBEAT_INTERVAL_SECONDS,
    ) {
    }

    /**
     * @throws UploadGone              when the intent is absent, someone else's or expired
     * @throws UploadInProgress        when another request is streaming it
     * @throws ContentLengthRequired   when the PUT declares no length
     * @throws ContentLengthMismatch   when the declared length is not the declared size
     * @throws InvalidOriginalFilename when the filename is missing, repeated or breaks a rule
     * @throws UploadCapacityExhausted when a streaming cap is reached
     * @throws UploadAborted           when the body did not become a document
     */
    public function upload(UploadEvidenceCommand $command): UploadOutcome
    {
        $claimed = $this->claimUpload->claim($command);

        if ($claimed instanceof Document) {
            return UploadOutcome::alreadyFiled($claimed);
        }

        $this->timeBudget->arm(UploadLimits::STREAM_TIME_LIMIT_SECONDS);

        try {
            $digest = $this->stream($claimed, $command->body);

            $this->timeBudget->arm(UploadLimits::COMMIT_TIME_LIMIT_SECONDS);
            $document = $this->commitUpload->commit($claimed, $digest);
        } catch (Throwable $throwable) {
            // Whatever ended the upload, its staged object and its intent go; only the answer differs.
            $this->expireUpload->expire($claimed->intentId, $claimed->scopeId);

            throw $throwable;
        }

        try {
            $this->promoteDocument->promote($document->documentId(), $document->scopeId());
        } catch (Throwable) {
            // Filed and owned by its row: the roll-forward sweep finishes the promotion.
        }

        return UploadOutcome::filed($document);
    }

    /**
     * Seals the body into staging and returns the digest of the plaintext, or throws {@see UploadAborted} when
     * the body is not the one declared.
     *
     * @param resource $body
     */
    private function stream(ClaimedUpload $claimed, $body): string
    {
        $meter = new UploadMeter(
            $body,
            $claimed->declaredSize,
            fn (): bool => $this->heartbeat($claimed->intentId),
            heartbeatIntervalSeconds: $this->heartbeatIntervalSeconds,
        );
        $source = $meter->open();

        try {
            $this->storage->stageWith(
                $claimed->scopeId,
                fn ($sink) => $this->sealer->seal(
                    EncryptionScopeId::forDocument($claimed->scopeId),
                    $source,
                    $sink,
                    self::ORIGINAL_ASSOCIATED_DATA,
                ),
            );
        } catch (Throwable $throwable) {
            throw $this->isAbortedBody($throwable, $meter) ? UploadAborted::create() : $throwable;
        } finally {
            \fclose($source);
        }

        $digest = $meter->digest();

        if (
            null !== $meter->abortReason()
            || $meter->bytesRead() !== $claimed->declaredSize
            || (null !== $claimed->declaredSha256 && !\hash_equals($claimed->declaredSha256, $digest))
        ) {
            throw UploadAborted::create();
        }

        return $digest;
    }

    /**
     * Whether a failure of the seal means the body is not the one declared rather than a fault of the server: an
     * empty body, a scope the reaper closed because the lease was lost, or a read the meter refused. A sink that
     * cannot be written is a server fault and propagates as itself.
     */
    private function isAbortedBody(Throwable $sealing, UploadMeter $meter): bool
    {
        return $sealing instanceof CannotSealEmptyStream
            || $sealing instanceof DekDestroyed
            || ($sealing instanceof StreamTransferFailed && null !== $meter->abortReason());
    }

    /**
     * Renews the lease under the intent row's lock and re-arms the time budget; false when the upload is no
     * longer live, which makes the meter abort the stream.
     */
    private function heartbeat(string $intentId): bool
    {
        try {
            $alive = $this->transactionManager->transactional(function () use ($intentId): bool {
                $intent = $this->intents->lockForUpdate($intentId);

                if (!$intent instanceof UploadIntent || !$intent->renewLease($this->intents->now())) {
                    return false;
                }

                $this->intents->save($intent);

                return true;
            });
        } catch (Throwable) {
            return false;
        }

        if ($alive) {
            $this->timeBudget->arm(UploadLimits::STREAM_TIME_LIMIT_SECONDS);
        }

        return $alive;
    }
}
