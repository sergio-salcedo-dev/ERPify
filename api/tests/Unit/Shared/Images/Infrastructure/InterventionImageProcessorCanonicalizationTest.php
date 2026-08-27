<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * AC 14 (anti-polyglot) and the canonicalization contract's #4 (single frame), #5 (EXIF
 * orientation baked into pixels) and #6 (non-semantic metadata never affects the digest).
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
}
