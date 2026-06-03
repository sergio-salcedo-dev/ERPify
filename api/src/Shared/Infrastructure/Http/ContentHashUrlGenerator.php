<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds public URLs for content-addressed resources. When MEDIA_PUBLIC_BASE_URL is
 * configured every resource is served from that origin; otherwise the current request's
 * route is used, falling back to a relative path off-request (e.g. CLI / worker).
 */
final readonly class ContentHashUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        #[Autowire('%env(MEDIA_PUBLIC_BASE_URL)%')]
        private string $publicBaseUrl,
    ) {
    }

    /**
     * @param string $routeName  Route serving the resource from this origin
     * @param string $pathPrefix Leading path segment of the public URL, e.g. "/api/v1/media/"
     */
    public function generate(string $contentHash, string $routeName, string $pathPrefix): string
    {
        $base = \trim($this->publicBaseUrl);

        if ('' !== $base) {
            return \rtrim($base, '/') . $pathPrefix . $contentHash;
        }

        if ($this->requestStack->getCurrentRequest() instanceof Request) {
            return $this->urlGenerator->generate(
                $routeName,
                ['hash' => $contentHash],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        }

        return $pathPrefix . $contentHash;
    }
}
