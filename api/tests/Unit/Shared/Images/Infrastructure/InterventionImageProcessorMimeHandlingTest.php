<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\Exception\FailureCategory;
use Erpify\Shared\Images\Domain\Exception\ImageDecodingFailed;
use Erpify\Shared\Images\Domain\Exception\ImageProcessingFailed;
use Erpify\Shared\Images\Domain\Exception\UnsupportedImageFormat;
use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * AC 8 (MIME outside the allowlist), AC 13 (decoder-confusion defense) and AC 9 (library
 * exceptions never cross untranslated) — exercised at all three stages that can raise one
 * (decode, normalize, encode).
 *
 * @internal
 */
#[CoversClass(InterventionImageProcessor::class)]
final class InterventionImageProcessorMimeHandlingTest extends TestCase
{
    use InterventionImageProcessorTestHelpers;

    public function testRejectsAFormatOutsideTheAllowlist(): void
    {
        try {
            $this->processor()->process('this is plain text, not an image');
            $this->fail('Expected UnsupportedImageFormat to be thrown.');
        } catch (UnsupportedImageFormat $unsupportedImageFormat) {
            $this->assertSame(FailureCategory::UnsupportedFormat, $unsupportedImageFormat->failureCategory());
        }
    }

    public function testRejectsWhenDeclaredMediaTypeDoesNotMatchTheDetectedFormatEvenIfBothAreAllowlisted(): void
    {
        try {
            $this->processor()->process($this->fixture('valid.jpg'), 'image/png');
            $this->fail('Expected UnsupportedImageFormat to be thrown.');
        } catch (UnsupportedImageFormat $unsupportedImageFormat) {
            $this->assertSame(FailureCategory::MimeMismatch, $unsupportedImageFormat->failureCategory());
        }
    }

    public function testAcceptsWhenTheDeclaredMediaTypeMatchesTheDetectedFormat(): void
    {
        $canonical = $this->processor()->process($this->fixture('valid.jpg'), 'image/jpeg');

        $this->assertSame('image/jpeg', $canonical->mediaType);
    }

    public function testAcceptsANullDeclaredMediaType(): void
    {
        $canonical = $this->processor()->process($this->fixture('valid.jpg'));

        $this->assertSame('image/jpeg', $canonical->mediaType);
    }

    public function testTranslatesADecoderFailureIntoADomainException(): void
    {
        // Truncated to half its length: the SOF0 segment near the start of the file survives (so
        // the declared-dimension preflight reads a real, in-budget size and lets it through), but
        // the entropy-coded scan data does not, so the full decode fails.
        $fixture = $this->fixture('valid.jpg');
        $truncated = \substr($fixture, 0, (int) (\strlen($fixture) / 2));

        try {
            $this->processor()->process($truncated);
            $this->fail('Expected ImageDecodingFailed to be thrown.');
        } catch (ImageDecodingFailed $imageDecodingFailed) {
            $this->assertSame(FailureCategory::DecodeFailure, $imageDecodingFailed->failureCategory());
            $this->assertInstanceOf(Throwable::class, $imageDecodingFailed->getPrevious());
        }
    }

    public function testTranslatesANormalizeFailureIntoADomainException(): void
    {
        // A configured maxOutputDimension of 0 makes normalize()'s scaleDown(0, 0) call raise
        // Intervention's own InvalidArgumentException — a real library-level failure at this stage,
        // not a contrived one.
        $processor = $this->processor(maxOutputDimension: 0);

        try {
            $processor->process($this->fixture('valid.jpg'));
            $this->fail('Expected ImageProcessingFailed to be thrown.');
        } catch (ImageProcessingFailed $imageProcessingFailed) {
            $this->assertSame(FailureCategory::ProcessingFailure, $imageProcessingFailed->failureCategory());
            $this->assertInstanceOf(Throwable::class, $imageProcessingFailed->getPrevious());
        }
    }

    public function testTranslatesAnEncodeFailureIntoADomainException(): void
    {
        // A configured encodingQuality outside 0-100 makes the Jpeg/Webp encoder's own
        // InvalidArgumentException surface at encode() — again a real library-level failure, not a
        // contrived one.
        $processor = $this->processor(encodingQuality: 500);

        try {
            $processor->process($this->fixture('valid.jpg'));
            $this->fail('Expected ImageProcessingFailed to be thrown.');
        } catch (ImageProcessingFailed $imageProcessingFailed) {
            $this->assertSame(FailureCategory::ProcessingFailure, $imageProcessingFailed->failureCategory());
            $this->assertInstanceOf(Throwable::class, $imageProcessingFailed->getPrevious());
        }
    }
}
