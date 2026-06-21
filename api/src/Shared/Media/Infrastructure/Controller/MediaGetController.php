<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Infrastructure\Controller;

use Erpify\Shared\Http\Infrastructure\ContentAddressedHttpCache;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Exception\MediaNotFoundException;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/media/{hash}', name: 'shared_media_get', requirements: ['hash' => '[a-f0-9]{64}'], methods: ['GET'])]
final readonly class MediaGetController
{
    public function __construct(
        private MediaRepository $mediaRepository,
        private ContentAddressedHttpCache $httpCache,
    ) {
    }

    public function __invoke(Request $request, string $hash): Response
    {
        $notModified = $this->httpCache->isNotModified($request, $hash);

        if ($notModified && $this->mediaRepository->existsByContentHash($hash)) {
            $response = new Response();
            $response->setStatusCode(Response::HTTP_NOT_MODIFIED);
            $this->httpCache->applyHeaders($response, $hash);

            return $response;
        }

        $media = $this->mediaRepository->findByContentHash($hash);

        if (!$media instanceof Media) {
            throw MediaNotFoundException::withContentHash($hash);
        }

        $response = new Response($media->getRawBytes());
        $response->headers->set('Content-Type', $media->getMimeType());
        $response->headers->set('Content-Length', (string) $media->getByteSize());

        $this->httpCache->applyHeaders($response, $hash);

        return $response;
    }
}
