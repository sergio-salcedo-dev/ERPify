<?php

declare(strict_types=1);

namespace Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener;

use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\ErrorContract\Infrastructure\Http\RateLimitSnapshot;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Per-IP rate limit for the public HTTP surface, scoped to `/api/*`.
 *
 * Backed by the `anonymous_api` limiter declared in
 * `config/packages/rate_limiter.yaml` (sliding_window). Symfony binds the factory through the
 * autowiring naming convention `<rate_limiter_name_camel_case>Limiter` — i.e.
 * `RateLimiterFactoryInterface $anonymousApiLimiter` — so no manual service alias is needed.
 *
 * `kernel.request` (priority {@see REQUEST_PRIORITY} = 512) — runs AFTER
 * {@see \Erpify\Shared\Http\Infrastructure\CorrelationIdListener} (1024) so the correlation id
 * is already minted when the limiter records a probe, and BEFORE Symfony's `RouterListener`
 * (default 32) so rate limiting applies to every `/api/*` request, including 404s. Limiting
 * before routing is intentional: an attacker enumerating endpoints (`/api/v1/users/<id>`,
 * `/api/v1/users/<id+1>`, …) must NOT escape the budget by hammering paths that resolve to
 * `NotFoundHttpException`.
 *
 * Sub-requests (ESI fragments, forwards) are skipped on both events — only the main request
 * consumes a token, only the main response carries the headers.
 *
 * `kernel.response` (priority {@see RESPONSE_PRIORITY} = -128) — stamps the IETF-draft
 * `RateLimit-*` family AND the legacy `X-RateLimit-*` family on every main response (accepted
 * AND 429-rejected paths). Two families because:
 *   - `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` follow the active IETF
 *     draft for rate-limit header standardisation and are the values most modern clients
 *     (curl ≥ 7.84, browsers' DevTools network panel, observability vendors) auto-surface.
 *   - `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` are the de-facto
 *     legacy names emitted by GitHub / Stripe / Shopify; many SDKs hard-code these names.
 *     Emitting both costs ~120 bytes per response — a fair trade for compatibility.
 * `Retry-After` is emitted ONLY on the rejected path (per RFC 9110 §10.2.3). What it renders is a
 * {@see RateLimitSnapshot} read back off the request attribute; if none is there (rate limiting was
 * skipped — non-`/api/` path, sub-request, preflight) no headers are added. The snapshot is not
 * necessarily this listener's: a per-target budget spent at a controller edge stamps its own, and the
 * response then describes the budget that actually refused rather than the per-IP one it left alone.
 * `RateLimit-Reset` / `X-RateLimit-Reset` use the delta-seconds convention (not an epoch timestamp)
 * so callers do not need to know the server clock to act on it.
 *
 * `Reset` semantics follow Symfony's `RateLimit::getRetryAfter()` exactly. For
 * `sliding_window` that yields, in order: (a) `now` for an accepted request that still
 * leaves tokens — so `Reset` floors to `1s` via the `\max(1, …)` clamp; (b) the
 * replenish timestamp for the request that consumed the LAST token; (c) the moment the
 * oldest hit ages out of the window for a rejected request. This is intentionally
 * narrower than the IETF draft definition ("seconds until the quota fully resets") —
 * clients that need the absolute window reset should derive it from the policy
 * interval rather than this header. The chosen semantics match what Symfony's stock
 * helpers emit, so downstream observability tooling interoperates with the wider
 * ecosystem without surprise.
 *
 * CORS preflight (`OPTIONS`) requests are skipped before the limiter is consumed.
 * Nelmio's `CorsListener` runs at priority 250 (after this listener's 512) and short-
 * circuits preflights with a 200; budgeting them would either drop a legitimate
 * preflight under load or — worse — return a 429 in place of the preflight, breaking
 * CORS for cross-origin clients without any actual API work having been performed.
 * The trade-off is that an attacker can spam `OPTIONS /api/…` without consuming the
 * budget; this is acceptable because preflights cannot exercise application code
 * (they bypass the router) and the limiter still gates every non-preflight method
 * including those an attacker would actually use to enumerate the API.
 *
 * Client IP resolution is via `Request::getClientIp()`. For correct values behind FrankenPHP's
 * embedded Caddy / a load balancer, callers MUST configure `framework.trusted_proxies` (env
 * `SYMFONY_TRUSTED_PROXIES`) so `X-Forwarded-For` is honoured. Without trusted proxies the
 * limiter keys on the immediate connection IP — still safe (over-limits a NAT pool) but not
 * granular per real client. Documented in `docs/api-error-contract.md`.
 *
 * Null `getClientIp()` (only possible when the underlying socket has no remote address, i.e.
 * CLI / unit tests) collapses onto the literal `'unknown'` so the limiter still receives a
 * stable key. Production traffic never lands here — FrankenPHP always populates the address.
 *
 * Worker-mode safe: `final readonly`, only injected dependencies, no static state. The
 * snapshot is stored on the request attribute (per-request scope), not on the listener
 * instance.
 *
 * On limiter rejection, the listener throws {@see RateLimitExceeded} (implements the
 * `RateLimited` marker) instead of setting a response directly. The existing
 * {@see ExceptionResponder} converts it
 * into a conforming RFC 9457 Problem Details 429 envelope, preserving the project's single
 * error-contract pipeline. The response listener then stamps the rate-limit headers
 * on top of that 429 body.
 */
final readonly class RateLimitListener
{
    public const int REQUEST_PRIORITY = 512;

    public const int RESPONSE_PRIORITY = -128;

    public const string UNKNOWN_CLIENT_KEY = 'unknown';

    public function __construct(
        #[Autowire(service: 'limiter.anonymous_api')]
        private RateLimiterFactoryInterface $anonymousApiLimiter,
        private ApiRequestMatcher $apiRequestMatcher,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::REQUEST_PRIORITY)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->apiRequestMatcher->matches($request)) {
            return;
        }

        if (Request::METHOD_OPTIONS === $request->getMethod()) {
            return;
        }

        $limiterKey = $this->resolveLimiterKey($request);
        $limiter = $this->anonymousApiLimiter->create($limiterKey);
        $snapshot = RateLimitSnapshot::of($limiter->consume(1), $limiterKey);

        $snapshot->stampOn($request);

        if (!$snapshot->accepted) {
            throw $snapshot->refusal();
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: self::RESPONSE_PRIORITY)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $snapshot = RateLimitSnapshot::readFrom($event->getRequest());

        if (!$snapshot instanceof RateLimitSnapshot) {
            return;
        }

        $this->stampHeaders($event->getResponse(), $snapshot);
    }

    private function resolveLimiterKey(Request $request): string
    {
        $clientIp = $request->getClientIp();

        return \is_string($clientIp) && '' !== $clientIp ? $clientIp : self::UNKNOWN_CLIENT_KEY;
    }

    private function stampHeaders(Response $response, RateLimitSnapshot $snapshot): void
    {
        $headers = $response->headers;

        $headers->set('RateLimit-Limit', (string) $snapshot->limit);
        $headers->set('RateLimit-Remaining', (string) $snapshot->remaining);
        $headers->set('RateLimit-Reset', (string) $snapshot->retryAfterSeconds);

        $headers->set('X-RateLimit-Limit', (string) $snapshot->limit);
        $headers->set('X-RateLimit-Remaining', (string) $snapshot->remaining);
        $headers->set('X-RateLimit-Reset', (string) $snapshot->retryAfterSeconds);

        if (!$snapshot->accepted) {
            $headers->set('Retry-After', (string) $snapshot->retryAfterSeconds);
        }
    }
}
