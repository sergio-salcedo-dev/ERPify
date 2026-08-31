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
 * How the route answers a request header it EVALUATES and the three it ignores.
 *
 * Split from {@see ImageGetControllerTest}, which asks what a successful response carries. The seam is the
 * request rather than the response: everything here is a conditional or precondition header, and what the
 * slice does with each one — honour `If-None-Match`, ignore `Range`, `If-Modified-Since` and `If-Match` —
 * is a separate contract from the representation itself, and the one a client is most likely to probe.
 *
 * @internal
 */
#[CoversClass(ImageGetController::class)]
final class ImageGetConditionalRequestTest extends TestCase
{
    /** PNG magic, so a sniffing implementation would answer something other than the row's `image/webp`. */
    private const string BYTES = "\x89PNG\r\n\x1a\n and then some";

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
