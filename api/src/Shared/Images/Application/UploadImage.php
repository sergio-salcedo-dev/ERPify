<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\ImageProcessor;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Persistence\Application\TransactionManager;
use SensitiveParameter;

/**
 * Ingestion use case (naming category 6 — `docs/rules/cqrs-naming.md`): bytes in, an {@see Image}
 * out. Invoked directly, never through a bus. Mints the {@see ImageId} itself — no public signature
 * anywhere in this module accepts one as input — and never accepts anything but raw bytes plus an
 * optional declared media type: no `UploadedFile`/`File`/`SplFileInfo`/path/filename/URL, and no
 * conservation-contract parameter — a caller with an "Evidence" contract has no signature to invoke
 * here at all.
 */
final readonly class UploadImage
{
    public function __construct(
        private ImageProcessor $imageProcessor,
        private ImageStorage $imageStorage,
        private ImageRepository $imageRepository,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * The order is deliberate and each step is where it is for a reason.
     *
     * The aggregate is built BEFORE anything is written. Its constructor validates the digest, the
     * dimensions, the byte size and the media type, and raises on any of them — so building it last would
     * let a degenerate canonical representation leave an orphaned object behind through a VALIDATION path,
     * not an infrastructure one, and this use case compensates for neither.
     *
     * The write to storage is deliberately OUTSIDE the transaction. It is not a database operation, so no
     * rollback can undo it; wrapping it would only give the appearance of atomicity across two systems that
     * have none. What is accepted instead is a bounded, one-directional residue: if anything after a
     * successful write fails, the stored object outlives the row that would have referenced it. Nothing
     * here reverses or collects it — that is a scope decision, not a permanent prohibition.
     *
     * The identifier reaches the caller only through the returned aggregate, and only after the row has
     * committed. The claim is about the RETURN VALUE, not about the universe: the id exists in memory
     * before that, and may exist in a log or an exception message.
     */
    public function upload(#[SensitiveParameter] string $bytes, ?string $declaredMediaType = null): Image
    {
        $canonicalImage = $this->imageProcessor->process($bytes, $declaredMediaType);

        $image = new Image(
            ImageId::generate(),
            $canonicalImage->digest,
            $canonicalImage->mediaType,
            $canonicalImage->width,
            $canonicalImage->height,
            $canonicalImage->byteSize,
        );

        // Only the canonical bytes are ever stored. The originals the caller supplied end here.
        $this->imageStorage->store($image->id(), $canonicalImage->bytes);

        $this->transactionManager->transactional(function () use ($image): void {
            $this->imageRepository->save($image);
        });

        return $image;
    }
}
