<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Application\CanonicalImageFinder;
use Erpify\Shared\Images\Domain\Read\ImageTemporarilyUnavailable;
use Erpify\Shared\Images\Domain\Read\ReadFailureCategory;
use Erpify\Shared\Images\Domain\Read\UnservableImage;
use Erpify\Shared\Images\Domain\Storage\ImageStorageFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the read use case does with a substrate that misbehaves, one arm per verdict.
 *
 * These are the arms an honest double cannot reach: a digest mismatch needs a storage that answers with
 * bytes other than the ones it holds, and the permanent verdict needs one that fails in a class no retry
 * resolves. Both exist as doubles precisely because the property under test is what the caller does with a
 * misbehaving substrate, not what it does with bytes — which is why they are filed apart from the outcome
 * tests, the way the storage adapter's own failure-contract tests are.
 *
 * The row that matters most is the permanent one: translating it would give it a `ServiceUnavailable`
 * marker and a 503, which tells a client to retry something only an operator can fix.
 *
 * @internal
 */
#[CoversClass(CanonicalImageFinder::class)]
#[CoversClass(ImageTemporarilyUnavailable::class)]
#[CoversClass(UnservableImage::class)]
final class CanonicalImageFinderFailureTranslationTest extends TestCase
{
    public function testARetryableSubstrateFailureIsTranslatedForTheWire(): void
    {
        $repository = new InMemoryImageRepository();
        $image = ImageFinderHarness::image();
        $repository->save($image);

        $this->expectException(ImageTemporarilyUnavailable::class);

        ImageFinderHarness::finder($repository, new UnavailableImageStorage())->find($image->id());
    }

    public function testAPermanentSubstrateFailureEscapesUntranslated(): void
    {
        // Translating it would give it a `ServiceUnavailable` marker and a 503, which tells the client to
        // retry something only an operator can fix.
        $repository = new InMemoryImageRepository();
        $image = ImageFinderHarness::image();
        $repository->save($image);

        $this->expectException(ImageStorageFailed::class);

        ImageFinderHarness::finder($repository, new PermanentlyFailingImageStorage())->find($image->id());
    }

    public function testBytesThatDoNotHashToTheRowsDigestAreNeverServed(): void
    {
        $repository = new InMemoryImageRepository();
        $image = ImageFinderHarness::image();
        $repository->save($image);

        try {
            ImageFinderHarness::finder($repository, new CorruptingImageStorage('tampered bytes'))->find($image->id());
            $this->fail('A digest mismatch must never be served.');
        } catch (UnservableImage $unservableImage) {
            $this->assertSame(ReadFailureCategory::DigestMismatch, $unservableImage->readFailure());
        }
    }

    public function testAnObjectAboveTheServingBudgetIsRefusedBeforeStorageIsTouched(): void
    {
        // Refusing on the size the ROW declares is the whole point: reading first is what exhausts memory,
        // and a memory exhaustion is a fatal error rather than a throwable, so nothing downstream would
        // turn it into a response at all.
        $repository = new InMemoryImageRepository();
        $image = ImageFinderHarness::image();
        $repository->save($image);

        try {
            ImageFinderHarness::finder($repository, new PermanentlyFailingImageStorage(), 1)->find($image->id());
            $this->fail('An object above the serving budget must be refused.');
        } catch (UnservableImage $unservableImage) {
            $this->assertSame(ReadFailureCategory::ObjectTooLarge, $unservableImage->readFailure());
        }
    }

    /**
     * The comparison is `>`, so an object of EXACTLY the budget is servable and one byte more is not.
     *
     * The case above uses a budget of 1 against a row of 27, which is true whichever way the comparison is
     * spelled — so `>` drifting to `>=` was invisible, and it would refuse an object the budget was written
     * to admit. A boundary that nothing sits on is a boundary nothing pins.
     */
    #[DataProvider('provideTheBudgetBoundaryIsInclusiveCases')]
    public function testTheBudgetBoundaryIsInclusive(int $budgetOffset, bool $servable): void
    {
        $repository = new InMemoryImageRepository();
        $storage = new InMemoryImageStorage();
        $image = ImageFinderHarness::storedImage($storage, $repository);

        $finder = ImageFinderHarness::finder($repository, $storage, $image->byteSize() + $budgetOffset);

        if (!$servable) {
            $this->expectException(UnservableImage::class);
        }

        $found = $finder->find($image->id());

        $this->assertSame(ImageFinderHarness::BYTES, $found->bytes);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function provideTheBudgetBoundaryIsInclusiveCases(): iterable
    {
        yield 'one byte below the declared size' => [-1, false];
        yield 'exactly the declared size' => [0, true];
        yield 'one byte above the declared size' => [+1, true];
    }
}
