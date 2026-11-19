<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\Entity\Image;
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
 * **A refusal keeps nothing.** The row was never inserted, so the stored bytes are deleted before the refusal
 * propagates. Any other failure leaves them where they are: whether that transaction committed is not known from here,
 * and deleting the bytes under a row that did commit would leave the derivative unreadable rather than released. That
 * residue is the one {@see UploadImage} already accepts, bytes with no row.
 */
final readonly class MaterialiseDerivative
{
    public function __construct(
        private ImageProcessor $imageProcessor,
        private ImageStorage $imageStorage,
        private ImageRepository $imageRepository,
        private TransactionManager $transactionManager,
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
        } catch (DerivativeOriginNotLive $derivativeOriginNotLive) {
            $this->discard($image->id());

            throw $derivativeOriginNotLive;
        }

        return $image->id();
    }

    /**
     * Best effort: a delete that fails leaves the residue named above and is logged by class, and the refusal is what
     * the caller sees.
     */
    private function discard(ImageId $id): void
    {
        try {
            $this->imageStorage->delete($id);
        } catch (Throwable $throwable) {
            $this->logger->warning('images.derivative.discard_failed', [
                'event' => 'images.derivative.discard_failed',
                'exceptionClass' => $throwable::class,
            ]);
        }
    }
}
