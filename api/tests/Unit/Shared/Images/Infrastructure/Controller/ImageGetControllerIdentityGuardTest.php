<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure\Controller;

use Erpify\Shared\Images\Application\CanonicalImageFinder;
use Erpify\Shared\Images\Application\ReadFailureReporter;
use Erpify\Shared\Images\Infrastructure\Controller\ImageGetController;
use Erpify\Shared\Images\Infrastructure\Http\HttpCacheValidator;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Shared\Images\Application\PermanentlyFailingImageStorage;
use Erpify\Tests\Unit\Shared\Images\Application\UnreadableImageRepository;
use Erpify\Tests\Unit\Shared\Images\Infrastructure\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The ORDER of the guard, which is the one property of this route that cannot be observed from its response.
 *
 * A malformed identifier and an absent one both fail, so a test that only reads the status cannot tell a
 * guard that runs first from one that runs after a lookup. The collaborators here therefore RAISE if they
 * are reached at all: the assertion is that they were not.
 *
 * It is filed apart from the response tests because it needs the opposite fixture — doubles that refuse to
 * work rather than doubles that hold an image — and folding both into one class put it over the coupling
 * ceiling with no reader served by the merge.
 *
 * @internal
 */
#[CoversClass(ImageGetController::class)]
final class ImageGetControllerIdentityGuardTest extends TestCase
{
    public function testAMalformedIdentifierIsRefusedBeforeAnythingIsLookedUp(): void
    {
        $controller = new ImageGetController(
            new CanonicalImageFinder(
                new UnreadableImageRepository(),
                new PermanentlyFailingImageStorage(),
                new ReadFailureReporter(new RecordingLogger()),
                1_048_576,
            ),
            new HttpCacheValidator(),
        );

        $this->expectException(InvalidUuidException::class);

        $controller(new Request(), 'not-a-uuid');
    }
}
