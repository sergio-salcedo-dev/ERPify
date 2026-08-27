<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure;

use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Interfaces\EncoderInterface;

/**
 * Maps an allowlisted, detected media type to the Intervention encoder that produces it — kept
 * apart from {@see InterventionImageProcessor} for the same coupling reason as
 * {@see ImagePreflightGuard}: one class per concern keeps each one's PHPMD coupling-between-objects
 * count under the project's threshold.
 *
 * `strip: true` on Jpeg/Webp is what makes the canonicalization contract's "non-semantic metadata
 * never survives" property hold for those formats — without it, Intervention re-embeds whatever
 * EXIF the decoder read off the original input into the re-encoded bytes.
 */
final class MediaTypeEncoderFactory
{
    public static function for(string $mediaType, int $quality): EncoderInterface
    {
        return match ($mediaType) {
            'image/jpeg' => new JpegEncoder(quality: $quality, strip: true),
            'image/webp' => new WebpEncoder(quality: $quality, strip: true),
            'image/gif' => new GifEncoder(),
            default => new PngEncoder(),
        };
    }
}
