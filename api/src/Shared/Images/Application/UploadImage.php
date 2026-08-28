<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\ImageProcessor;

/**
 * Ingestion use case (naming category 6 — `docs/rules/cqrs-naming.md`): bytes in, an {@see Image}
 * out. Invoked directly, never through a bus. Mints the {@see ImageId} itself — no public signature
 * anywhere in this module accepts one as input (NFR4) — and never accepts anything but raw bytes
 * plus an optional declared media type: no `UploadedFile`/`File`/`SplFileInfo`/path/filename/URL
 * (NFR6), and no conservation-contract parameter (FR2/FR6) — a caller with an "Evidence" contract
 * has no signature to invoke here at all.
 *
 */
final readonly class UploadImage
{
    public function __construct(private ImageProcessor $imageProcessor)
    {
    }

    public function upload(string $bytes, ?string $declaredMediaType = null): Image
    {
        $canonicalImage = $this->imageProcessor->process($bytes, $declaredMediaType);

        return new Image(
            ImageId::generate(),
            $canonicalImage->digest,
            $canonicalImage->mediaType,
            $canonicalImage->width,
            $canonicalImage->height,
            $canonicalImage->byteSize,
        );
    }
}
