<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The `If-None-Match` half of conditional GET: does the validator the client already holds still describe
 * the representation we are about to serve?
 *
 * **Recovered from a retired content-addressed helper, and deliberately only half of it.** That class also
 * applied the cache headers, and it applied them `public, max-age=31536000, immutable` — a shared-cache
 * directive on responses this route serves only to an authenticated caller. Bringing that half back would
 * have put one user's image into any cache on the path. The freshness policy therefore lives at the call
 * site, where the reason for each directive can be read next to the response it is on, and what is reused
 * here is the part that has no policy in it: matching an entity tag against its three legal spellings.
 *
 * The name drops "content-addressed" on purpose. Nothing in this module addresses content by its hash — the
 * digest is used as an ATTRIBUTE of a representation identified by its `ImageId`, never as its address —
 * and carrying the old name forward would have implied a storage model this epic explicitly refused.
 */
final readonly class HttpCacheValidator
{
    /**
     * `getETags()` returns the raw comma-separated members of `If-None-Match`, so all three legal forms of
     * a strong tag reach here — quoted, weak-prefixed and, from lenient clients, bare. A `GET` compares with
     * the weak comparison function (RFC 9110 §13.1.2), under which `W/"x"` and `"x"` match, so accepting the
     * weak spelling is the specification rather than leniency.
     */
    public function isNotModified(Request $request, string $digest): bool
    {
        $etags = \array_filter($request->getETags(), \is_string(...));

        if ([] === $etags) {
            return false;
        }

        if (\in_array('*', $etags, true)) {
            return true;
        }

        $matching = [\sprintf('"%s"', $digest), \sprintf('W/"%s"', $digest), $digest];

        return [] !== \array_intersect($matching, $etags);
    }
}
