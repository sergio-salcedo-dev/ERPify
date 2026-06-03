<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conditional-GET and caching policy for content-addressed responses: the content hash
 * doubles as the ETag, so responses are immutable and safe to cache for a year.
 */
final readonly class ContentAddressedHttpCache
{
    private const string CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function applyHeaders(Response $response, string $hash): void
    {
        $response->setPublic();
        $response->headers->set('Cache-Control', self::CACHE_CONTROL);

        $response->setEtag($hash);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
    }

    public function isNotModified(Request $request, string $hash): bool
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
