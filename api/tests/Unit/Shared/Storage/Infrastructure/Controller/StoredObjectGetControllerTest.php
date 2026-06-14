<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Storage\Infrastructure\Controller;

use Erpify\Shared\Infrastructure\Http\ContentAddressedHttpCache;
use Erpify\Shared\Storage\Application\Port\StoragePort;
use Erpify\Shared\Storage\Application\Port\StoredObjectAccessPort;
use Erpify\Shared\Storage\Infrastructure\Controller\StoredObjectGetController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversClass(StoredObjectGetController::class)]
final class StoredObjectGetControllerTest extends TestCase
{
    private const string HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testReturnsNotFoundWhenMetadataIsMissing(): void
    {
        $controller = $this->controller(metadataExists: false, blobExists: true);

        $response = $controller($this->get(), self::HASH);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testServesBodyWhenObjectIsAvailable(): void
    {
        $controller = $this->controller(metadataExists: true, blobExists: true, mime: 'application/pdf');

        $response = $controller($this->get(), self::HASH);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('PDF', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function testReturnsNotModifiedWhenObjectIsAvailableAndEtagMatches(): void
    {
        $controller = $this->controller(metadataExists: true, blobExists: true);

        $response = $controller($this->get('"' . self::HASH . '"'), self::HASH);

        $this->assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testReturnsNotFoundRatherThan304WhenBlobIsMissingDespiteMatchingEtag(): void
    {
        $controller = $this->controller(metadataExists: true, blobExists: false);

        $response = $controller($this->get('"' . self::HASH . '"'), self::HASH);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), (string) $response->getContent());
    }

    private function controller(
        bool $metadataExists,
        bool $blobExists,
        string $mime = 'application/octet-stream',
    ): StoredObjectGetController {
        $objectStorage = $this->createStub(StoragePort::class);
        $objectStorage->method('exists')->willReturn($blobExists);
        $objectStorage->method('read')->willReturn('PDF');

        $access = $this->createStub(StoredObjectAccessPort::class);
        $access->method('existsAnyWithContentHash')->willReturn($metadataExists);
        $access->method('getMimeTypeForContentHash')->willReturn($mime);

        return new StoredObjectGetController($objectStorage, $access, new ContentAddressedHttpCache());
    }

    private function get(?string $ifNoneMatch = null): Request
    {
        $request = Request::create('/stored-objects/' . self::HASH);

        if (null !== $ifNoneMatch) {
            $request->headers->set('If-None-Match', $ifNoneMatch);
        }

        return $request;
    }
}
