<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Infrastructure\Controller;

use Erpify\Shared\Infrastructure\Http\ContentAddressedHttpCache;
use Erpify\Shared\Storage\Application\Port\ObjectStoragePort;
use Erpify\Shared\Storage\Application\Port\StoredObjectAccessPort;
use Erpify\Shared\Storage\Domain\ContentAddressableObjectKey;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/stored-objects/{hash}',
    name: 'shared_stored_object_get',
    requirements: ['hash' => '[a-f0-9]{64}'],
    methods: ['GET'],
)]
final readonly class StoredObjectGetController
{
    public function __construct(
        private ObjectStoragePort $objectStoragePort,
        private StoredObjectAccessPort $storedObjectAccessPort,
        private ContentAddressedHttpCache $httpCache,
    ) {
    }

    public function __invoke(Request $request, string $hash): Response
    {
        if (!$this->storedObjectAccessPort->existsAnyWithContentHash($hash)) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($this->httpCache->isNotModified($request, $hash)) {
            $response = new Response();
            $response->setStatusCode(Response::HTTP_NOT_MODIFIED);
            $this->httpCache->applyHeaders($response, $hash);

            return $response;
        }

        return $this->buildBodyResponse($hash);
    }

    /**
     * Materialises the full-body response (or 404 if the object key is missing).
     * Kept separate so {@see __invoke} stays a thin "resolve hash → delegate" shim.
     */
    private function buildBodyResponse(string $hash): Response
    {
        $key = ContentAddressableObjectKey::fromContentHash($hash);

        if (!$this->objectStoragePort->exists($key)) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        $bytes = $this->objectStoragePort->read($key);
        $mime = $this->storedObjectAccessPort->getMimeTypeForContentHash($hash) ?? 'application/octet-stream';

        $response = new Response($bytes);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Content-Length', (string) \strlen($bytes));

        $this->httpCache->applyHeaders($response, $hash);

        return $response;
    }
}
