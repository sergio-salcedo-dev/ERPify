<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Sets, replaces and removes the single image an aggregate holds, and is the one place a release of an image is
 * requested. A consumer owns its column and its route; it never creates the deletion request itself, which is why
 * no consumer story edits the publisher registry.
 *
 * **The order is the protocol.** The bytes are stored first ({@see UploadImage}, outside any transaction, because no
 * rollback can undo a write to storage). One transaction then takes the owner row `FOR UPDATE`, reads the id it
 * holds, attaches the new one and queues the release of the old one — so the new id and the request to destroy the
 * old one commit together or not at all, and the owner never points at bytes that are already queued for deletion.
 *
 * **The compensation cannot run inside the failed transaction.** Doctrine closes the manager on a failure and the
 * publish guard demands an active transaction, so when the attach transaction throws, the release of the NEW id is
 * published by a second transaction afterwards. If that one fails too, the original failure is the one the caller
 * sees and the second is only logged by class: the object it would have released is then an orphan, which
 * `PRODUCTION_SECURITY_CHECKLIST.md` §7 (the image residuals) records as accepted, together with a crash between
 * the store and the attach.
 *
 * **The compensation re-reads the owner with the same `FOR UPDATE`** ({@see ImageHolder::lockAndRead()}), and that
 * lock may be what failed the attach: under contention the re-read can fail too, nothing is queued, and the new id is
 * an orphan — logged by class, accepted.
 *
 * **A lost acknowledgement plus a later writer can queue one id twice.** The commit landed and was reported as
 * failed, the re-read finds the new id held and leaves it alone, and a later replace then releases that id as its
 * previous one, which may already have been released once. That is safe because `DeleteImage` is idempotent
 * (`DeleteImageIntegrationTest::testASecondDeliveryOfTheSameRequestLeavesTheSameStateAndDoesNotFail`).
 *
 * **What is not here.** No reference counting and no check that anyone else holds an id: an owner holds the id it
 * minted and nothing else does, which is the single-holder rule the consumer epic states. A failure inside
 * {@see UploadImage} after `store()` leaves an orphan too, with the same record.
 */
final readonly class ImageAttachment
{
    public function __construct(
        private UploadImage $uploadImage,
        private TransactionManager $transactionManager,
        private EventBus $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws Throwable whatever the processor, the holder or the transaction raised. A failure that left the owner
     *                   pointing elsewhere has the new id queued for release before it propagates; one raised after
     *                   the commit landed (a lost acknowledgement) finds the owner already holding the new id, which
     *                   is then left alone and the previous id's release stays queued
     */
    public function replace(
        ImageHolder $holder,
        string $ownerId,
        #[SensitiveParameter]
        string $bytes,
        ?string $declaredMediaType = null,
    ): ImageId {
        $image = $this->uploadImage->upload($bytes, $declaredMediaType);
        $newId = $image->id();

        try {
            $this->transactionManager->transactional(function () use ($holder, $ownerId, $newId): void {
                $previousId = $holder->lockAndRead($ownerId);
                $holder->attach($ownerId, $newId);

                if ($previousId instanceof ImageId) {
                    $this->requestRelease($previousId);
                }
            });
        } catch (Throwable $throwable) {
            $this->releaseAfterFailure($holder, $ownerId, $newId);

            throw $throwable;
        }

        return $newId;
    }

    /**
     * Idempotent: an owner that holds nothing is left as it is and nothing is queued.
     */
    public function remove(ImageHolder $holder, string $ownerId): void
    {
        $this->transactionManager->transactional(function () use ($holder, $ownerId): void {
            $heldId = $holder->lockAndRead($ownerId);

            if (!$heldId instanceof ImageId) {
                return;
            }

            $holder->attach($ownerId, null);
            $this->requestRelease($heldId);
        });
    }

    /**
     * Must run inside a transaction: the publish guard refuses anything else.
     */
    private function requestRelease(ImageId $id): void
    {
        $this->eventBus->publish(new ImageDeletionRequested($id->toString()));
    }

    /**
     * A throwable out of the attach transaction does not prove it rolled back: the commit may have landed and its
     * acknowledgement been lost. So the owner is read again, under its lock, and the new id is released only when it
     * does not hold it — releasing an id the owner points at would leave it over destroyed bytes. An owner that is gone
     * holds nothing, so its new id is released too.
     */
    private function releaseAfterFailure(ImageHolder $holder, string $ownerId, ImageId $newId): void
    {
        try {
            $this->transactionManager->transactional(function () use ($holder, $ownerId, $newId): void {
                if ($this->holds($holder, $ownerId, $newId)) {
                    return;
                }

                $this->requestRelease($newId);
            });
        } catch (Throwable $throwable) {
            $this->logger->warning('images.attachment.compensation_failed', [
                'event' => 'images.attachment.compensation_failed',
                'exceptionClass' => $throwable::class,
            ]);
        }
    }

    private function holds(ImageHolder $holder, string $ownerId, ImageId $id): bool
    {
        try {
            return $holder->lockAndRead($ownerId)?->equals($id) ?? false;
        } catch (NotFound) {
            return false;
        }
    }
}
