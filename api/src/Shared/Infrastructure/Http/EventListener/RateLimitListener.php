<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\EventListener;

use Erpify\Shared\Domain\Exception\RateLimitExceeded;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
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
 * {@see \Erpify\Shared\Infrastructure\Http\CorrelationIdListener} (1024) so the correlation id
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
 * `Retry-After` is emitted ONLY on the rejected path (per RFC 9110 §10.2.3). The response
 * listener reads the snapshot stamped on the request attribute by the request listener; if
 * the attribute is missing (rate limiting was skipped, e.g. non-/api/ path or sub-request)
 * no headers are added.
 *
 * `Retry-After` value is `\max(1, $retryAfter->getTimestamp() - $now)` — the lower bound of
 * 1 second prevents emitting `Retry-After: 0` when the clock advances exactly to the reset
 * boundary between consume and response. `RateLimit-Reset` / `X-RateLimit-Reset` use the
 * same delta-seconds convention (not an epoch timestamp) so callers do not need to know the
 * server clock to act on it.
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
 * {@see \Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder} converts it
 * into a conforming RFC 9457 Problem Details 429 envelope, preserving the project's single
 * error-contract pipeline (NFR26). The response listener then stamps the rate-limit headers
 * on top of that 429 body.
 */
final readonly class RateLimitListener
{
    public const int REQUEST_PRIORITY = 512;

    public const int RESPONSE_PRIORITY = -128;

    public const string ATTRIBUTE_KEY = '_rate_limit_snapshot';

    public const string UNKNOWN_CLIENT_KEY = 'unknown';

    public const string API_PATH_PREFIX = '/api/';

    public function __construct(
        #[Autowire(service: 'limiter.anonymous_api')]
        private RateLimiterFactoryInterface $anonymousApiLimiter,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::REQUEST_PRIORITY)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!\str_starts_with($request->getPathInfo(), self::API_PATH_PREFIX)) {
            return;
        }

        $limiterKey = $this->resolveLimiterKey($request);
        $limiter = $this->anonymousApiLimiter->create($limiterKey);
        $rateLimit = $limiter->consume(1);

        $request->attributes->set(self::ATTRIBUTE_KEY, $this->snapshot($rateLimit, $limiterKey));

        if (!$rateLimit->isAccepted()) {
            throw new RateLimitExceeded(
                retryAfterSeconds: $this->retryAfterSeconds($rateLimit),
                limit: $rateLimit->getLimit(),
                remaining: \max(0, $rateLimit->getRemainingTokens()),
                limiterKey: $limiterKey,
            );
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: self::RESPONSE_PRIORITY)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $snapshot = $this->readSnapshot($event->getRequest()->attributes->get(self::ATTRIBUTE_KEY));

        if (null === $snapshot) {
            return;
        }

        $this->stampHeaders($event->getResponse(), $snapshot);
    }

    private function resolveLimiterKey(Request $request): string
    {
        $clientIp = $request->getClientIp();

        return \is_string($clientIp) && '' !== $clientIp ? $clientIp : self::UNKNOWN_CLIENT_KEY;
    }

    /**
     * @return array{limit: int, remaining: int, reset: int, retry_after: int, accepted: bool, key: string}
     */
    private function snapshot(RateLimit $rateLimit, string $limiterKey): array
    {
        $retryAfter = $this->retryAfterSeconds($rateLimit);

        return [
            'limit' => $rateLimit->getLimit(),
            'remaining' => \max(0, $rateLimit->getRemainingTokens()),
            'reset' => $retryAfter,
            'retry_after' => $retryAfter,
            'accepted' => $rateLimit->isAccepted(),
            'key' => $limiterKey,
        ];
    }

    private function retryAfterSeconds(RateLimit $rateLimit): int
    {
        return \max(1, $rateLimit->getRetryAfter()->getTimestamp() - \time());
    }

    /**
     * Defensive shape narrowing of the request attribute. Returns `null` when the attribute is
     * absent or its shape has drifted (a foreign listener tampered with the bag, a future code
     * path stored an unrelated value under the same key). PHPStan then sees the narrowed
     * `array{...}` shape inside the caller without resorting to a runtime cast.
     *
     * @return array{limit: int, remaining: int, reset: int, retry_after: int, accepted: bool, key: string}|null
     */
    private function readSnapshot(mixed $stored): ?array
    {
        if (!\is_array($stored)) {
            return null;
        }

        if (
            !\array_key_exists('limit', $stored) || !\is_int($stored['limit'])
            || !\array_key_exists('remaining', $stored) || !\is_int($stored['remaining'])
            || !\array_key_exists('reset', $stored) || !\is_int($stored['reset'])
            || !\array_key_exists('retry_after', $stored) || !\is_int($stored['retry_after'])
            || !\array_key_exists('accepted', $stored) || !\is_bool($stored['accepted'])
            || !\array_key_exists('key', $stored) || !\is_string($stored['key'])
        ) {
            return null;
        }

        return [
            'limit' => $stored['limit'],
            'remaining' => $stored['remaining'],
            'reset' => $stored['reset'],
            'retry_after' => $stored['retry_after'],
            'accepted' => $stored['accepted'],
            'key' => $stored['key'],
        ];
    }

    /**
     * @param array{limit: int, remaining: int, reset: int, retry_after: int, accepted: bool, key: string} $snapshot
     */
    private function stampHeaders(Response $response, array $snapshot): void
    {
        $headers = $response->headers;

        $headers->set('RateLimit-Limit', (string) $snapshot['limit']);
        $headers->set('RateLimit-Remaining', (string) $snapshot['remaining']);
        $headers->set('RateLimit-Reset', (string) $snapshot['reset']);

        $headers->set('X-RateLimit-Limit', (string) $snapshot['limit']);
        $headers->set('X-RateLimit-Remaining', (string) $snapshot['remaining']);
        $headers->set('X-RateLimit-Reset', (string) $snapshot['reset']);

        if (!$snapshot['accepted']) {
            $headers->set('Retry-After', (string) $snapshot['retry_after']);
        }
    }
}
