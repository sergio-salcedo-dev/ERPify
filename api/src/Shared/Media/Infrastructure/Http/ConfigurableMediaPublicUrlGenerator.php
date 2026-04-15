<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Infrastructure\Http;

use Erpify\Shared\Media\Application\Port\MediaPublicUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsAlias(MediaPublicUrlGenerator::class)]
final readonly class ConfigurableMediaPublicUrlGenerator implements MediaPublicUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        #[Autowire('%env(MEDIA_PUBLIC_BASE_URL)%')]
        private string $mediaPublicBaseUrl,
    ) {}

    public function urlForContentHash(string $contentHash): string
    {
        $base = \trim($this->mediaPublicBaseUrl);

        if ('' !== $base) {
            return \rtrim($base, '/') . '/api/v1/media/' . $contentHash;
        }

        if ($this->requestStack->getCurrentRequest() instanceof Request) {
            return $this->urlGenerator->generate(
                'shared_media_get',
                ['hash' => $contentHash],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        }

        return '/api/v1/media/' . $contentHash;
    }
}
