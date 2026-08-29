<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\CanonicalImage;
use Erpify\Shared\Images\Domain\Exception\ImageDecodingFailed;
use Erpify\Shared\Images\Domain\Exception\ImageProcessingException;
use Erpify\Shared\Images\Domain\Exception\ImageProcessingFailed;
use Erpify\Shared\Images\Domain\ImageProcessor;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * The only layer allowed to import Intervention/GD (`docs/rules/architecture.md`). Pipeline:
 * preflight ({@see ImagePreflightGuard}) → decode → normalize (orient, single-frame, strip
 * profile, bound output dimension) → encode ({@see MediaTypeEncoderFactory}) → digest (computed by
 * {@see CanonicalImage} itself from the canonical bytes).
 *
 * Canonicalization is v1 IMPLICIT: no version field is persisted anywhere in this module. The
 * trigger for introducing an explicit versioning scheme is the first time this pipeline's output
 * changes in code merged to `main`.
 *
 * Supported formats extend the epic's cited precedent (jpeg/png/webp) with GIF. Verified against
 * the installed `intervention/image` source: under the GD driver, the non-GIF decode path always
 * calls plain `imagecreatefromstring()`, which decodes only the first frame of an animated WebP
 * regardless of any application-level handling — so "reduce an animated source to one frame" can
 * never be genuinely exercised through WebP here. GIF is the only allowlisted format whose
 * animation is actually decoded in full (via the `intervention/gif` companion package, gated by
 * the `decodeAnimation: false` driver option below), which is what makes the frame limit and the
 * canonicalization contract's "exactly one frame" property a real, tested claim rather than a
 * vacuous one.
 *
 * Exception translation catches ONLY `Intervention\Image\Exceptions\ImageException`
 * at each stage — deliberately, not `\Throwable`. A `\TypeError`/`\ArgumentCountError`/OOM `\Error`
 * is a programming or environment defect, not a business rejection of untrusted image content, and
 * disguising it as one would hide exactly the failure that must surface loudly rather than be
 * silently absorbed as "just a bad image": where the library's exception hierarchy is trusted, the
 * catch is the narrowest one, never the widest. The residual risk
 * this accepts is that a decode-library-internal failure Intervention itself does not wrap in
 * `ImageException` would propagate uncaught rather than being translated/logged here — verified
 * empirically as reachable only for genuinely malformed library usage (a misconfigured
 * `encoding_quality`/`max_output_dimension`, both covered by dedicated tests), never observed from
 * malicious-but-well-formed input across the fixtures this module tests against.
 */
final readonly class InterventionImageProcessor implements ImageProcessor
{
    private ImageManager $imageManager;

    private ImagePreflightGuard $preflightGuard;

    public function __construct(
        #[Autowire(param: 'erpify.images.max_input_bytes')]
        int $maxInputBytes,
        #[Autowire(param: 'erpify.images.max_decoded_pixels')]
        int $maxDecodedPixels,
        #[Autowire(param: 'erpify.images.max_input_dimension')]
        int $maxInputDimension,
        #[Autowire(param: 'erpify.images.max_output_dimension')]
        private int $maxOutputDimension,
        #[Autowire(param: 'erpify.images.encoding_quality')]
        private int $encodingQuality,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
        // `decodeAnimation: false` is the frame-count resource guard: it makes the GD driver decode
        // only the first frame of an animated source instead of
        // materializing every frame, bounding the resource cost regardless of how many frames the
        // original carries.
        $this->imageManager = new ImageManager(new Driver(), decodeAnimation: false);
        $this->preflightGuard = new ImagePreflightGuard($maxInputBytes, $maxDecodedPixels, $maxInputDimension);
    }

    public function process(#[SensitiveParameter] string $bytes, ?string $declaredMediaType = null): CanonicalImage
    {
        $format = 'unknown';

        try {
            $this->preflightGuard->check($bytes, $declaredMediaType, $format);
        } catch (ImageProcessingException $imageProcessingException) {
            $this->reject('images.processing.rejected', 'preflight', $format, $imageProcessingException);
        }

        try {
            $image = $this->imageManager->decodeBinary($bytes);
        } catch (ImageException $imageException) {
            $this->reject('images.processing.failure', 'decode', $format, new ImageDecodingFailed($imageException));
        }

        try {
            $image = $this->normalize($image);
        } catch (ImageException $imageException) {
            $failure = new ImageProcessingFailed($imageException);
            $this->reject('images.processing.failure', 'normalize', $format, $failure);
        }

        try {
            $encoder = MediaTypeEncoderFactory::for($format, $this->encodingQuality);
            $canonicalBytes = $image->encode($encoder)->toString();
        } catch (ImageException $imageException) {
            $failure = new ImageProcessingFailed($imageException);
            $this->reject('images.processing.failure', 'encode', $format, $failure);
        }

        return new CanonicalImage($canonicalBytes, $format, $image->width(), $image->height());
    }

    private function normalize(ImageInterface $image): ImageInterface
    {
        // EXIF orientation → pixels (canonicalization contract #5). The driver already applies this
        // during decode (`autoOrientation` defaults to true) — calling it again here is an
        // idempotent, explicit statement of the property rather than reliance on that default.
        $image = $image->orient();

        // Animation → exactly one frame (canonicalization contract #4), regardless of how many the
        // source carries.
        if ($image->isAnimated()) {
            $image = $image->removeAnimation(0);
        }

        // Non-semantic metadata (ICC profile) never survives into the canonical bytes
        // (canonicalization contract #6) — encoder-level `strip` (Jpeg/Webp) handles EXIF/comments.
        $image = $image->removeProfile();

        // Aspect ratio preserved, only shrinks, never enlarges (canonicalization contract #7).
        return $image->scaleDown($this->maxOutputDimension, $this->maxOutputDimension);
    }

    /**
     * Logs, then re-throws — the only place this class raises. Ownership: this adapter emits the
     * observability signal for every failure it detects or translates; `UploadImage` never logs the
     * same failure again.
     */
    private function reject(
        string $event,
        string $operation,
        string $format,
        ImageProcessingException $exception,
    ): never {
        try {
            $this->logger->info($event, [
                'event' => $event,
                'operation' => $operation,
                'format' => $format,
                'failure_category' => $exception->failureCategory()->value,
            ]);
        } catch (Throwable) {
            // Swallowed by design — observability is never load-bearing for the rejection itself.
        }

        throw $exception;
    }
}
