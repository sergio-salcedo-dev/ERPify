<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Clock\Domain\NativeClock;
use Erpify\Shared\Images\Application\CanonicalImageFinder;
use Erpify\Shared\Images\Application\FailureSignalWindow;
use Erpify\Shared\Images\Application\ReadFailureReporter;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Tests\Unit\Shared\Images\Infrastructure\RecordingLogger;

/**
 * The wiring every read-path test needs, written once.
 *
 * Four test classes were each repeating the same aggregate construction and the same three-collaborator
 * assembly, so a change to either signature meant four edits and the arithmetic of the digest had four
 * places to drift. Naming it here also keeps each test class about its own subject rather than about how a
 * finder is built.
 *
 * @internal
 */
final class ImageFinderHarness
{
    public const string BYTES = 'the canonical bytes';

    /**
     * A row whose digest and byte size genuinely describe {@see BYTES}, because a fixture that lies about
     * either would make the verification arms pass or fail for the wrong reason.
     */
    public static function image(): Image
    {
        return new Image(
            ImageId::generate(),
            \hash('sha256', self::BYTES),
            'image/webp',
            10,
            10,
            \strlen(self::BYTES),
        );
    }

    /**
     * Both stores seeded coherently — the row AND its bytes — which is the only state a successful read can
     * be asked about.
     */
    public static function storedImage(InMemoryImageStorage $storage, InMemoryImageRepository $repository): Image
    {
        $image = self::image();
        $repository->save($image);
        $storage->store($image->id(), self::BYTES);

        return $image;
    }

    public static function finder(
        InMemoryImageRepository $repository,
        ImageStorage $storage,
        int $maxServedBytes = 1_048_576,
        ?RecordingLogger $logger = null,
    ): CanonicalImageFinder {
        return new CanonicalImageFinder(
            $repository,
            $storage,
            new ReadFailureReporter($logger ?? new RecordingLogger(), new FailureSignalWindow(new NativeClock())),
            $maxServedBytes,
        );
    }
}
