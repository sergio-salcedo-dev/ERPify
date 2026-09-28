<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;

/**
 * The single owner of the "is this an `/api` request?" boundary. Every one of the application's own kernel
 * listeners that only acts on the API surface — the session admission gate, the error-contract responder,
 * the rate limiter, the audit hooks — asks this class, and the firewall's `access_control` catch-all in
 * `security.yaml` names {@see self::PATH_PATTERN} through `!php/const`, so what requires a session and what
 * those listeners see are one definition rather than two that happen to agree.
 *
 * The pattern is evaluated by the same {@see PathRequestMatcher} the security bundle builds for that rule,
 * and the reuse is the point: it matches against the `rawurldecode()`d path, as the router does when it
 * dispatches. A prefix check on the raw path info let `/%61pi/v1/me` — authenticated by the firewall and
 * served by the router — past every listener here, the session gate included, so a revoked session with a
 * still-valid cookie was admitted. It also covers `/api` and `/apiX`, which the firewall already guards.
 *
 * Nelmio CORS is not one of those listeners: it keys its own `^/api/` on the raw path, and on the spellings
 * it misses it fails closed — it adds no CORS headers, so a cross-origin browser cannot read the response.
 */
final readonly class ApiRequestMatcher
{
    public const string PATH_PATTERN = '^/api';

    private PathRequestMatcher $pathMatcher;

    public function __construct()
    {
        $this->pathMatcher = new PathRequestMatcher(self::PATH_PATTERN);
    }

    public function matches(Request $request): bool
    {
        return $this->pathMatcher->matches($request);
    }
}
