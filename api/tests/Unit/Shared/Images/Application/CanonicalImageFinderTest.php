<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Application\CanonicalImageBytes;
use Erpify\Shared\Images\Application\CanonicalImageFinder;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Read\ImageNotAvailable;
use Erpify\Shared\Images\Domain\Read\UnservableImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the read path ANSWERS when the substrate behaves: the bytes and their attributes when both stores
 * hold the image, and the one 404 that covers both ways of not having it.
 *
 * The arm worth naming is the second: an identifier this deployment never stored must not reach storage at
 * all, which is why its double raises if it is asked. That ordering is what keeps an unknown identifier
 * from being a log producer — the measured correction behind this module's log-volume decision.
 *
 * How a MISBEHAVING substrate is translated is a different question with a different set of doubles, and
 * lives in {@see CanonicalImageFinderFailureTranslationTest}.
 *
 * @internal
 */
#[CoversClass(CanonicalImageFinder::class)]
#[CoversClass(CanonicalImageBytes::class)]
#[CoversClass(ImageNotAvailable::class)]
#[CoversClass(UnservableImage::class)]
final class CanonicalImageFinderTest extends TestCase
{
    public function testItReturnsTheStoredBytesWithTheMediaTypeAndDigestOfTheRow(): void
    {
        $storage = new InMemoryImageStorage();
        $repository = new InMemoryImageRepository();
        $image = ImageFinderHarness::storedImage($storage, $repository);

        $found = ImageFinderHarness::finder($repository, $storage)->find($image->id());

        $this->assertSame(ImageFinderHarness::BYTES, $found->bytes);
        $this->assertSame('image/webp', $found->mediaType);
        $this->assertSame(\hash('sha256', ImageFinderHarness::BYTES), $found->digest);
    }

    public function testAnAbsentRowIsNotAvailableAndStorageIsNeverAsked(): void
    {
        // The order matters beyond tidiness: an identifier this deployment never stored must not reach
        // storage at all, which is what keeps an unknown id from being a log producer.
        $storage = new PermanentlyFailingImageStorage();

        $this->expectException(ImageNotAvailable::class);

        ImageFinderHarness::finder(new InMemoryImageRepository(), $storage)->find(ImageId::generate());
    }

    public function testAConfirmedByteAbsenceIsTheSameAnswerAsAnAbsentRow(): void
    {
        $storage = new InMemoryImageStorage();
        $repository = new InMemoryImageRepository();
        $image = ImageFinderHarness::storedImage($storage, $repository);
        $storage->objects = [];

        $this->expectException(ImageNotAvailable::class);

        ImageFinderHarness::finder($repository, $storage)->find($image->id());
    }
}
