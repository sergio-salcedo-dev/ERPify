<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Infrastructure\Controller;

use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/media/{hash}', name: 'shared_media_get', requirements: ['hash' => '[a-f0-9]{64}'], methods: ['GET'])]
final readonly class MediaGetController
{
    private const string CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __construct(
        private MediaRepository $mediaRepository,
    ) {}

    public function __invoke(Request $request, string $hash): Response
    {
        if ($this->ifNoneMatchEqualsHash($request, $hash) && $this->mediaRepository->existsActiveByContentHash($hash)) {
            $response = new Response();
            $response->setStatusCode(Response::HTTP_NOT_MODIFIED);
            $this->applyCacheAndSecurityHeaders($response, $hash);

            return $response;
        }

        $media = $this->mediaRepository->findActiveByContentHash($hash);
        if (!$media instanceof \Erpify\Shared\Media\Domain\Entity\Media) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        $response = new Response($media->getRawBytes());
        $response->headers->set('Content-Type', $media->getMimeType());
        $response->headers->set('Content-Length', (string) $media->getByteSize());
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

        foreach ($request->getETags() as $tag) {
            if ($tag === $hash) {
                return true;
            }
        }

        return \str_contains($header, $hash);
    }
}
