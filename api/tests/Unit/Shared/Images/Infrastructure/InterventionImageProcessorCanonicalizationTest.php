<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The anti-polyglot guarantee, plus three properties of the canonicalization contract: a single
 * frame, EXIF orientation baked into the pixels, and non-semantic metadata never affecting the
 * digest.
 *
 * @internal
 */
#[CoversClass(InterventionImageProcessor::class)]
final class InterventionImageProcessorCanonicalizationTest extends TestCase
{
    use InterventionImageProcessorTestHelpers;

    public function testNeverReturnsAnyByteOfTheOriginalUploadedInput(): void
    {
        $polyglot = $this->fixture('polyglot.png');
        $canonical = $this->processor()->process($polyglot);

        $this->assertStringNotContainsString('<?php', $canonical->bytes);
        $this->assertStringNotContainsString('trailing payload', $canonical->bytes);
        $this->assertNotSame($polyglot, $canonical->bytes);
    }

    public function testAnAnimatedSourceCanonicalizesToExactlyOneFrame(): void
    {
        $canonical = $this->processor()->process($this->fixture('animated.gif'));

        $manager = new ImageManager(new Driver(), decodeAnimation: true);
        $reDecoded = $manager->decodeBinary($canonical->bytes);

        $this->assertFalse($reDecoded->isAnimated());
    }

    public function testExifOrientedPixelsMatchAnAlreadyCorrectlyOrientedEquivalent(): void
    {
        $processor = $this->processor();

        $alreadyCorrect = $processor->process($this->fixture('orientation-normal.jpg'));
        $exifTagged = $processor->process($this->fixture('orientation-tag6.jpg'));

        $this->assertSame($alreadyCorrect->digest, $exifTagged->digest);
    }

    public function testDifferingNonSemanticMetadataProducesTheSameDigestForIdenticalPixels(): void
    {
        $processor = $this->processor();

        $a = $processor->process($this->fixture('metadata-a.jpg'));
        $b = $processor->process($this->fixture('metadata-b.jpg'));

        $this->assertSame($a->digest, $b->digest);
    }

    public function testDifferingNonSemanticMetadataProducesTheSameDigestForIdenticalPixelsInPng(): void
    {
        // Contract point 6 is a property of the pipeline for every allowlisted format, not just
        // Jpeg (whose encoder gets an explicit `strip: true`) — PngEncoder carries no such option,
        // so this pins that GD's PNG re-encode path drops ancillary tEXt chunks on its own.
        $processor = $this->processor();

        $a = $processor->process($this->fixture('metadata-a.png'));
        $b = $processor->process($this->fixture('metadata-b.png'));

        $this->assertSame($a->digest, $b->digest);
    }
}
