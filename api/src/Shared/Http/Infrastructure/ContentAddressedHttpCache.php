<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure;

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
        $etags = \array_filter($request->getETags(), \is_string(...));

        if ([] === $etags) {
            return false;
        }

        if (\in_array('*', $etags, true)) {
            return true;
        }

        // Match the hash against its valid If-None-Match forms: strong, weak (W/"…"), and unquoted.
        $matchingEtags = [\sprintf('"%s"', $hash), \sprintf('W/"%s"', $hash), $hash];

        return [] !== \array_intersect($matchingEtags, $etags);
    }
}
