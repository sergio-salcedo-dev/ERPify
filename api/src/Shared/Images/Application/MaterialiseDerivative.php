<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Erpify\Shared\Images\Domain\Exception\DerivativeOriginNotLive;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\ImageProcessor;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * The third entry point of ADR D2: another bounded context materialises an image that derives from one of its own
 * resources, and the image dies with that resource (D5, D8). The bytes go through the same pipeline as an upload, so
 * the media type set stays closed and only canonical bytes are stored.
 *
 * **The order is {@see UploadImage}'s, plus one lock.** The aggregate is built first, so a degenerate canonical
 * representation fails before anything is written; the bytes are stored outside any transaction, because no rollback
 * undoes a write to storage; then ONE transaction asks the origin's guard to lock it and admit a derivative, and only
 * then inserts the row. The guard's lock conflicts with whatever ends the origin's life, so a derivative either
 * commits before that end — and the owner's release finds it by origin — or is refused after it.
 *
 * **Bytes with no row are not left behind.** Whatever failed after the store — a refusal, a lost connection, a
 * timeout behind the owner's lock — the row is read again: a row that committed (its acknowledgement lost) is a
 * derivative its owner releases by origin, so its bytes stay; an absent row means the bytes belong to nothing, so they
 * are deleted, and when that delete fails a deletion request is queued, which the transport retries. The failure the
 * caller sees is always the original one.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the upload pipeline plus the compensation's own event and log
 */
final readonly class MaterialiseDerivative
{
    public function __construct(
        private ImageProcessor $imageProcessor,
        private ImageStorage $imageStorage,
        private ImageRepository $imageRepository,
        private TransactionManager $transactionManager,
        private EventBus $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws DerivativeOriginNotLive when the guard refuses the origin; nothing is kept
     */
    public function materialise(
        DerivativeOrigin $origin,
        OriginGuard $guard,
        #[SensitiveParameter]
        string $bytes,
        ?string $declaredMediaType = null,
    ): ImageId {
        $canonicalImage = $this->imageProcessor->process($bytes, $declaredMediaType);

        $image = new Image(
            ImageId::generate(),
            $canonicalImage->digest,
            $canonicalImage->mediaType,
            $canonicalImage->width,
            $canonicalImage->height,
            $canonicalImage->byteSize,
            $origin,
        );

        $this->imageStorage->store($image->id(), $canonicalImage->bytes);

        try {
            $this->transactionManager->transactional(function () use ($guard, $origin, $image): void {
                if (!$guard->admits($origin)) {
                    throw DerivativeOriginNotLive::create();
                }

                $this->imageRepository->save($image);
            });
        } catch (Throwable $throwable) {
            $this->discardUnlessKept($image->id());

            throw $throwable;
        }

        return $image->id();
    }

    /**
     * A row that cannot be read decides nothing: the bytes stay and the id is logged, since deleting them could strand
     * a row that did commit. The failed transaction closed the manager and the transaction manager replaced it, so the
     * read reaches the database rather than an identity map holding the rolled-back row.
     */
    private function discardUnlessKept(ImageId $id): void
    {
        try {
            $kept = $this->imageRepository->findById($id) instanceof Image;
        } catch (Throwable $throwable) {
            $this->report('images.derivative.discard_undecided', $id, $throwable);

            return;
        }

        if ($kept) {
            return;
        }

        try {
            $this->imageStorage->delete($id);
        } catch (Throwable) {
            $this->queueDeletion($id);
        }
    }

    private function queueDeletion(ImageId $id): void
    {
        try {
            $this->transactionManager->transactional(function () use ($id): void {
                $this->eventBus->publish(new ImageDeletionRequested($id->toString()));
            });
        } catch (Throwable $throwable) {
            $this->report('images.derivative.discard_failed', $id, $throwable);
        }
    }

    /**
     * The image id is the one handle an operator has on the bytes; it names a rendering of a document, never a person.
     */
    private function report(string $event, ImageId $id, Throwable $throwable): void
    {
        $this->logger->warning($event, [
            'event' => $event,
            'imageId' => $id->toString(),
            'exceptionClass' => $throwable::class,
        ]);
    }
}
