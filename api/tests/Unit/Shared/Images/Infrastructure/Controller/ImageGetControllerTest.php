<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure\Controller;

use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Infrastructure\Controller\ImageGetController;
use Erpify\Shared\Images\Infrastructure\Http\HttpCacheValidator;
use Erpify\Tests\Unit\Shared\Images\Application\ImageFinderHarness;
use Erpify\Tests\Unit\Shared\Images\Application\InMemoryImageRepository;
use Erpify\Tests\Unit\Shared\Images\Application\InMemoryImageStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the response carries, and what it refuses to carry, for each shape of request this route accepts.
 *
 * The collaborators are real rather than mocked — a real finder over in-memory doubles — because the
 * properties under test are properties of the CHAIN: that the identifier is validated before anything is
 * looked up, and that the media type comes off the row rather than off the bytes.
 *
 * @internal
 */
#[CoversClass(ImageGetController::class)]
final class ImageGetControllerTest extends TestCase
{
    /** PNG magic, so a sniffing implementation would answer something other than the row's `image/webp`. */
    private const string BYTES = "\x89PNG\r\n\x1a\n and then some";

    public function testItServesTheBytesWithTheMediaTypeOfTheRowRatherThanOfTheBytes(): void
    {
        [$controller, $image] = $this->controllerWithStoredImage();

        $response = $controller(new Request(), $image->id()->toString());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(self::BYTES, $response->getContent());
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertSame((string) \strlen(self::BYTES), $response->headers->get('Content-Length'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * Asserted as directives present rather than as a literal string, for two measured reasons: `HeaderBag`
     * serializes them in alphabetical order, and the stateful firewall's session listener rewrites the
     * header on `kernel.response`, adding `must-revalidate` and an `Expires` this unit never sees.
     */
    public function testTheSuccessfulResponseIsPrivateFreshForAnHourAndImmutable(): void
    {
        [$controller, $image] = $this->controllerWithStoredImage();

        $response = $controller(new Request(), $image->id()->toString());

        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        $this->assertTrue($response->headers->hasCacheControlDirective('immutable'));
    }

    /**
     * The one directive that would be a defect rather than a difference: it is what the retired helper this
     * validator was recovered from emitted, and it would put one caller's image into any cache on the path.
     */
    public function testTheResponseIsNeverPubliclyCacheable(): void
    {
        [$controller, $image] = $this->controllerWithStoredImage();

        $response = $controller(new Request(), $image->id()->toString());

        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function testTheValidatorIsAStrongTagDerivedFromTheDigest(): void
    {
        [$controller, $image] = $this->controllerWithStoredImage();

        $response = $controller(new Request(), $image->id()->toString());

        $this->assertSame(\sprintf('"%s"', $image->digest()), $response->headers->get('ETag'));
    }

    public function testAMatchingValidatorAnswersNotModifiedWithItsOwnValidatorAndFreshness(): void
    {
        // `setNotModified()` keeps what is already on the response, so a 304 built on a bare one would carry
        // neither — satisfying every other rule here and leaving the client nothing to send back next time.
        [$controller, $image] = $this->controllerWithStoredImage();
        $request = new Request();
        $request->headers->set('If-None-Match', \sprintf('"%s"', $image->digest()));

        $response = $controller($request, $image->id()->toString());

        $this->assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode(), (string) $response->getContent());
        $this->assertEmpty($response->getContent());
        $this->assertSame(\sprintf('"%s"', $image->digest()), $response->headers->get('ETag'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
    }

    /**
     * `Range` is ignored rather than honoured or refused, and nothing advertises otherwise: announcing a
     * capability this slice does not implement is worse than staying silent about it.
     */
    public function testARangeRequestIsAnsweredWithTheWholeBodyAndNoAdvertisement(): void
    {
        [$controller, $image] = $this->controllerWithStoredImage();
        $request = new Request();
        $request->headers->set('Range', 'bytes=0-3');

        $response = $controller($request, $image->id()->toString());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(self::BYTES, $response->getContent());
        $this->assertFalse($response->headers->has('Accept-Ranges'));
    }

    /**
     * `If-Modified-Since` is ignored, which is the other half of not opening a second validation axis: the
     * route emits no `Last-Modified`, so a client cannot have obtained one from here, and honouring the
     * request header would answer 304 against a validator this deployment never issued.
     *
     * Untested until a code review looked for it — `git grep -i if-modified-since api/tests api/features`
     * matched only the controller's own docblock, so planting a branch that answers 304 on this header
     * turned nothing red while three documents said it was ignored.
     */
    public function testAModificationDateConditionIsIgnoredAndTheWholeBodyIsAnswered(): void
    {
        [$controller, $image] = $this->controllerWithStoredImage();
        $request = new Request();
        $request->headers->set('If-Modified-Since', 'Sat, 01 Jan 2050 00:00:00 GMT');

        $response = $controller($request, $image->id()->toString());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(self::BYTES, $response->getContent());
    }

    /**
     * `If-Match` is not evaluated, so a mismatching one does not answer 412. It is a write-side precondition
     * and this route has no write to guard; refusing a read on it would invent a failure mode.
     */
    public function testAMismatchingIfMatchIsNotEvaluatedAndNeverAnswersPreconditionFailed(): void
    {
        [$controller, $image] = $this->controllerWithStoredImage();
        $request = new Request();
        $request->headers->set('If-Match', '"0000000000000000000000000000000000000000000000000000000000000000"');

        $response = $controller($request, $image->id()->toString());

        $this->assertNotSame(Response::HTTP_PRECONDITION_FAILED, $response->getStatusCode());
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(self::BYTES, $response->getContent());
    }

    /**
     * The second validation axis is deliberately not opened: `Image::createdAt()` exists, and emitting it
     * would be a second thing to keep true for nothing this slice needs.
     */
    public function testNoModificationDateIsEmitted(): void
    {
        [$controller, $image] = $this->controllerWithStoredImage();

        $response = $controller(new Request(), $image->id()->toString());

        $this->assertFalse($response->headers->has('Last-Modified'));
    }

    /**
     * A UUID is case-insensitive as a value and case-sensitive as a string, and the module reconciles the
     * two in the identifier's constructor. This asserts the route inherits that rather than sidestepping it —
     * the direction that shipped a confirmed erasure over bytes it had stranded for ever on the delete path.
     */
    public function testAnUpperCasedIdentifierServesTheSameBytesAndTheSameValidator(): void
    {
        [$controller, $image] = $this->controllerWithStoredImage();

        $lower = $controller(new Request(), $image->id()->toString());
        $upper = $controller(new Request(), \strtoupper($image->id()->toString()));

        $this->assertSame($lower->getContent(), $upper->getContent());
        $this->assertSame($lower->headers->get('ETag'), $upper->headers->get('ETag'));
    }

    /**
     * @return array{ImageGetController, Image}
     */
    private function controllerWithStoredImage(): array
    {
        $storage = new InMemoryImageStorage();
        $repository = new InMemoryImageRepository();
        $image = new Image(
            ImageId::generate(),
            \hash('sha256', self::BYTES),
            'image/webp',
            10,
            10,
            \strlen(self::BYTES),
        );
        $repository->save($image);
        $storage->store($image->id(), self::BYTES);

        $controller = new ImageGetController(
            ImageFinderHarness::finder($repository, $storage),
            new HttpCacheValidator(),
        );

        return [$controller, $image];
    }
}
