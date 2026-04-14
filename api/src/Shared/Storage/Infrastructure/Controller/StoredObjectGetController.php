<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Infrastructure\Controller;

use Erpify\Shared\Storage\Application\Port\ObjectStoragePort;
use Erpify\Shared\Storage\Application\Port\StoredObjectAccessPort;
use Erpify\Shared\Storage\Domain\ContentAddressableObjectKey;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/stored-objects/{hash}', name: 'shared_stored_object_get', requirements: ['hash' => '[a-f0-9]{64}'], methods: ['GET'])]
final readonly class StoredObjectGetController
{
    private const string CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __construct(
        private ObjectStoragePort $objectStoragePort,
        private StoredObjectAccessPort $storedObjectAccessPort,
    ) {}

    public function __invoke(Request $request, string $hash): Response
    {
        if (!$this->storedObjectAccessPort->existsAnyWithContentHash($hash)) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($this->ifNoneMatchEqualsHash($request, $hash)) {
            $response = new Response;
            $response->setStatusCode(Response::HTTP_NOT_MODIFIED);
            $this->applyCacheAndSecurityHeaders($response, $hash);

            return $response;
        }

        $key = ContentAddressableObjectKey::fromContentHash($hash);

        if (!$this->objectStoragePort->exists($key)) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        $bytes = $this->objectStoragePort->read($key);
        $mime = $this->storedObjectAccessPort->getMimeTypeForContentHash($hash) ?? 'application/octet-stream';

        $response = new Response($bytes);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Content-Length', (string) \strlen($bytes));
        $this->applyCacheAndSecurityHeaders($response, $hash);

        return $response;
    }

    private function applyCacheAndSecurityHeaders(Response $response, string $hash): void
    {
        $response->setPublic();
        $response->headers->set('Cache-Control', self::CACHE_CONTROL);
        $response->setEtag($hash);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
    }

    private function ifNoneMatchEqualsHash(Request $request, string $hash): bool
    {
        $header = $request->headers->get('If-None-Match');

        if (null === $header || '' === $header) {
            return false;
        }

        if (\array_any($request->getETags(), static fn ($tag): bool => $tag === $hash)) {
            return true;
        }

        return \str_contains($header, $hash);
    }
}
