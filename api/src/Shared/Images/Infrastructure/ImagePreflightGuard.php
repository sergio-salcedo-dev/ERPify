<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\Exception\EmptyImageInput;
use Erpify\Shared\Images\Domain\Exception\ImageResourceLimitExceeded;
use Erpify\Shared\Images\Domain\Exception\UnsupportedImageFormat;
use finfo;

/**
 * The cheap checks {@see InterventionImageProcessor} runs before a full decode (Task 5, Fase 0/1):
 * empty input → byte-size limit → MIME allowlist/mismatch → declared-dimension/pixel budget.
 * Extracted out of the processor as its own collaborator (SRP: "does this input even qualify for
 * a decode attempt" is a distinct concern from orchestrating decode/normalize/encode) — which is
 * also what keeps the processor's own coupling-between-objects count under the project's PHPMD
 * threshold (measured: inlining this logic put the processor at 19, over the limit of 13).
 */
final readonly class ImagePreflightGuard
{
    /** @var array<string, true> */
    private const array SUPPORTED_MEDIA_TYPES = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
        'image/gif' => true,
    ];

    public function __construct(
        private int $maxInputBytes,
        private int $maxDecodedPixels,
        private int $maxInputDimension,
    ) {
    }

    /**
     * @param-out string $detectedFormat the MIME detected from the content — set as soon as it is
     *                                    known, even when a later check in this method rejects it
     *
     * @throws EmptyImageInput
     * @throws ImageResourceLimitExceeded
     * @throws UnsupportedImageFormat
     */
    public function check(string $bytes, ?string $declaredMediaType, string &$detectedFormat): void
    {
        if ('' === $bytes) {
            throw new EmptyImageInput();
        }

        if (\strlen($bytes) > $this->maxInputBytes) {
            throw ImageResourceLimitExceeded::inputTooLarge();
        }

        $detected = $this->detectMediaType($bytes);
        $detectedFormat = $detected;

        // Sequence fixed by the story: allowlist first, using ONLY the detected format — the
        // declared one never selects the decoder and never widens what the allowlist admits.
        if (!isset(self::SUPPORTED_MEDIA_TYPES[$detected])) {
            throw UnsupportedImageFormat::notInAllowlist();
        }

        // A mismatch is rejected even when both the declared and the detected format are
        // individually allowlisted (decoder-confusion defense, AC 13). MIME type/subtype tokens are
        // case-insensitive (RFC 2045), so the comparison normalizes case on both sides — the
        // detected side is compared as-is too, since finfo already returns canonical lowercase.
        if (null !== $declaredMediaType && \strtolower(\trim($declaredMediaType)) !== $detected) {
            throw UnsupportedImageFormat::mimeMismatch();
        }

        $this->guardDeclaredDimensions($bytes);
    }

    private function detectMediaType(string $bytes): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return \is_string($detected) ? $detected : 'unknown';
    }

    /**
     * Reads only the declared header (never the full raster) so the resource guards run before the
     * decoder allocates anything proportional to the content (AC 7, 12). `getimagesizefromstring()`
     * warns on content it cannot parse — a deliberate, narrow suppression: the warning is discarded,
     * but the `false` outcome itself is NOT — it fails closed (below) rather than falling through to
     * an unbounded full decode, which would defeat the point of running this guard before one.
     *
     * @SuppressWarnings("PHPMD.ErrorControlOperator")
     */
    private function guardDeclaredDimensions(string $bytes): void
    {
        $size = @\getimagesizefromstring($bytes);

        if (false === $size) {
            // A format in the allowlist whose header this parser cannot read is treated as a
            // resource-limit rejection rather than let through to a decode this guard cannot bound —
            // "the decoder is itself an attack surface" (Dev Notes) applies here too: an unreadable
            // declared size is not evidence the decoded raster is small.
            throw ImageResourceLimitExceeded::inputDimensionExceeded();
        }

        [$declaredWidth, $declaredHeight] = $size;

        if ($declaredWidth > $this->maxInputDimension || $declaredHeight > $this->maxInputDimension) {
            throw ImageResourceLimitExceeded::inputDimensionExceeded();
        }

        if ($declaredWidth * $declaredHeight > $this->maxDecodedPixels) {
            throw ImageResourceLimitExceeded::decodedPixelsExceeded();
        }
    }
}
