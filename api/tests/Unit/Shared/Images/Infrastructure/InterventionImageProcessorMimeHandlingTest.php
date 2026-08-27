<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\Exception\FailureCategory;
use Erpify\Shared\Images\Domain\Exception\ImageDecodingFailed;
use Erpify\Shared\Images\Domain\Exception\UnsupportedImageFormat;
use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * AC 8 (MIME outside the allowlist), AC 13 (decoder-confusion defense) and AC 9 (library
 * exceptions never cross untranslated).
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
        // Passes the MIME/size/declared-dimension preflight (a JPEG SOI marker with no usable
        // header beyond it — getimagesizefromstring() cannot read a size, so that guard is a
        // silent no-op) but is not decodable content.
        $undecodable = "\xFF\xD8\xFF\x00garbage-not-a-real-jpeg-body";

        try {
            $this->processor()->process($undecodable);
            $this->fail('Expected ImageDecodingFailed to be thrown.');
        } catch (ImageDecodingFailed $imageDecodingFailed) {
            $this->assertSame(FailureCategory::DecodeFailure, $imageDecodingFailed->failureCategory());
            $this->assertInstanceOf(Throwable::class, $imageDecodingFailed->getPrevious());
        }
    }
}
