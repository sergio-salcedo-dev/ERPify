<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure;

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
     * @param string $routeName Route serving the resource from this origin; its path is the single
     *                          source of truth for the public URL (no hand-maintained prefix literal)
     */
    public function generate(string $contentHash, string $routeName): string
    {
        $base = \trim($this->publicBaseUrl);
        $parameters = ['hash' => $contentHash];

        if ('' !== $base) {
            return \rtrim($base, '/') . $this->urlGenerator->generate($routeName, $parameters);
        }

        if ($this->requestStack->getCurrentRequest() instanceof Request) {
            return $this->urlGenerator->generate(
                $routeName,
                $parameters,
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        }

        return $this->urlGenerator->generate($routeName, $parameters);
    }
}
