<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain;

use Erpify\Shared\Images\Domain\Exception\EmptyImageInput;
use Erpify\Shared\Images\Domain\Exception\ImageDecodingFailed;
use Erpify\Shared\Images\Domain\Exception\ImageProcessingFailed;
use Erpify\Shared\Images\Domain\Exception\ImageResourceLimitExceeded;
use Erpify\Shared\Images\Domain\Exception\UnsupportedImageFormat;
use SensitiveParameter;

/**
 * Capability port: bytes in, canonical representation out. Invocable in isolation, with no dependency
 * on {@see \Erpify\Shared\Images\Application\UploadImage} or any transport type. It never receives or
 * mints an {@see ImageId}.
 *
 * `$declaredMediaType` is the caller's own (unverified) claim about the content — never a transport
 * type or a location, so it does not carry a path/filename/URL. It never selects the decoder;
 * it is only compared against the media type this processor detects from the actual bytes.
 */
interface ImageProcessor
{
    /**
     * @throws EmptyImageInput            input is zero bytes
     * @throws ImageResourceLimitExceeded input size, declared dimensions or decoded pixel budget exceeded
     * @throws UnsupportedImageFormat     detected format outside the allowlist, or declared/detected mismatch
     * @throws ImageDecodingFailed        the decoder could not read the (allowlisted, within-limits) input
     * @throws ImageProcessingFailed      normalization or encoding failed
     */
    public function process(#[SensitiveParameter] string $bytes, ?string $declaredMediaType = null): CanonicalImage;
}
