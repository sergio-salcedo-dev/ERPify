<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Infrastructure\Http;

use Erpify\Shared\Storage\Application\Port\StoredObjectPublicUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsAlias(StoredObjectPublicUrlGenerator::class)]
final class ConfigurableStoredObjectPublicUrlGenerator implements StoredObjectPublicUrlGenerator
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly RequestStack $requestStack,
        #[Autowire('%env(MEDIA_PUBLIC_BASE_URL)%')]
        private readonly string $mediaPublicBaseUrl,
    ) {
    }

    public function urlForContentHash(string $contentHash): string
    {
        $base = trim($this->mediaPublicBaseUrl);
        if ($base !== '') {
            return rtrim($base, '/').'/api/v1/stored-objects/'.$contentHash;
        }

        if ($this->requestStack->getCurrentRequest() !== null) {
            return $this->router->generate(
                'shared_stored_object_get',
                ['hash' => $contentHash],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        }

        return '/api/v1/stored-objects/'.$contentHash;
    }
}
