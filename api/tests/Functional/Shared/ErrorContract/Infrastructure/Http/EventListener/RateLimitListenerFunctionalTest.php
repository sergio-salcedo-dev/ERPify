<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\ErrorContract\Infrastructure\Http\EventListener;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Integration test for {@see RateLimitListener}. Boots the real Symfony kernel and drives
 * `/api/v1/backoffice/health` (a stable 200 endpoint) through the full request → routing →
 * limiter → response pipeline.
 *
 * Isolation: `api/config/packages/test/cache.yaml` swaps the cache app pool to
 * `cache.adapter.array` (in-memory) so cross-test pollution is impossible. The flipside is
 * that `kernel.terminate` runs Symfony's `services_resetter`, which clears the in-memory
 * adapter between HTTP requests — so multi-request budget accumulation cannot be observed
 * by making N+1 sequential `KernelBrowser::request()` calls. Instead, each test that needs
 * a primed budget pre-consumes tokens by reaching into the `limiter.anonymous_api` factory
 * directly. The container — and therefore the limiter's cache pool — is the SAME instance
 * the listener resolves at request time, so the pre-consumed state is visible to the next
 * HTTP request handled before terminate fires.
 *
 * Budget: `api/.env.test` sets `RATE_LIMIT_ANONYMOUS_API_LIMIT=5` specifically so this
 * test (and the matching Behat scenario at `features/shared/rate_limiting/`) can prove
 * the 429 path in a small, fixed number of operations. Bumping that env value here would
 * re-introduce slow tests.
 *
 * @internal
 */
#[CoversNothing]
final class RateLimitListenerFunctionalTest extends WebTestCase
{
    private const string ENDPOINT = '/api/v1/backoffice/health';

    private const int BUDGET = 5;

    private const string UUID_V7_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /**
     * A unique REMOTE_ADDR per test so the limiter key (derived from `Request::getClientIp()`)
     * does not accidentally collide if a future change relaxes test isolation. The IP itself is
     * irrelevant — only its uniqueness within the test process matters.
     */
    private const string REMOTE_ADDR_FIRST_REQUEST = '203.0.113.10';

    private const string REMOTE_ADDR_EXHAUSTED = '203.0.113.20';

    private const string REMOTE_ADDR_PARTIAL = '203.0.113.30';

    private const string REMOTE_ADDR_NON_API = '203.0.113.40';

    private const string REMOTE_ADDR_OPTIONS = '203.0.113.50';

    public function testFirstRequestSucceedsAndStampsBothRateLimitHeaderFamilies(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->request(
            Request::METHOD_GET,
            self::ENDPOINT,
            server: ['REMOTE_ADDR' => self::REMOTE_ADDR_FIRST_REQUEST],
        );

        $response = $kernelBrowser->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        // IETF draft family.
        $this->assertSame((string) self::BUDGET, $response->headers->get('RateLimit-Limit'));
        $this->assertSame((string) (self::BUDGET - 1), $response->headers->get('RateLimit-Remaining'));
        $this->assertNotNull($response->headers->get('RateLimit-Reset'));

        // Legacy GitHub/Stripe/Shopify family — emitted in parallel for SDK compatibility.
        $this->assertSame((string) self::BUDGET, $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame((string) (self::BUDGET - 1), $response->headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Reset'));

        $this->assertNull(
            $response->headers->get('Retry-After'),
            'Retry-After must NOT be emitted on accepted responses (RFC 9110 §10.2.3).',
        );
    }

    public function testPartiallyConsumedBudgetIsReflectedInRateLimitRemainingHeader(): void
    {
        $kernelBrowser = self::createClient();

        // Burn 3 of the 5 tokens via the same limiter service the listener uses. The cache
        // pool is shared between this call and the HTTP request below (same container) and
        // services_resetter has not yet fired (no kernel.terminate has run for THIS client).
        $this->consumeTokens(self::REMOTE_ADDR_PARTIAL, 3);

        $kernelBrowser->request(
            Request::METHOD_GET,
            self::ENDPOINT,
            server: ['REMOTE_ADDR' => self::REMOTE_ADDR_PARTIAL],
        );

        $response = $kernelBrowser->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        // 3 tokens pre-consumed + 1 from this request = 4. Remaining = BUDGET - 4 = 1.
        $this->assertSame('1', $response->headers->get('RateLimit-Remaining'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testRequestPastBudgetReturns429RFC9457ProblemDetailsWithRetryAfter(): void
    {
        $kernelBrowser = self::createClient();

        // Pre-consume the full BUDGET so the very next HTTP request is over the limit.
        $this->consumeTokens(self::REMOTE_ADDR_EXHAUSTED, self::BUDGET);

        $kernelBrowser->request(
            Request::METHOD_GET,
            self::ENDPOINT,
            server: ['REMOTE_ADDR' => self::REMOTE_ADDR_EXHAUSTED],
        );
        $response = $kernelBrowser->getResponse();

        $this->assertSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));

        // Retry-After is mandatory on the rejected path and must be >= 1s (no Retry-After: 0).
        $retryAfter = $response->headers->get('Retry-After');
        $this->assertNotNull($retryAfter, 'Retry-After must ship on the rejected path.');
        $this->assertGreaterThanOrEqual(1, (int) $retryAfter);

        // Both header families still stamped on the 429 — drained but stable.
        $this->assertSame((string) self::BUDGET, $response->headers->get('RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('RateLimit-Remaining'));
        $this->assertSame((string) self::BUDGET, $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));

        // Body — RFC 9457 Problem Details + the limiter's extension keys (`limit`, `remaining`,
        // `retryAfterSeconds`). `RateLimited` marker → status 429 → `rate-limited` type.
        $body = $this->decodeBody($response->getContent());
        $this->assertSame('rate-limited', $body['type'] ?? null);
        $this->assertSame('Rate limit exceeded.', $body['title'] ?? null);
        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $body['status'] ?? null);

        $this->assertArrayHasKey('correlation-id', $body);
        $this->assertIsString($body['correlation-id']);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $body['correlation-id']);

        $this->assertArrayHasKey('instance', $body);
        $this->assertIsString($body['instance']);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $body['instance']);

        $this->assertSame(self::BUDGET, $body['limit'] ?? null);
        $this->assertSame(0, $body['remaining'] ?? null);
        $this->assertIsInt($body['retryAfterSeconds'] ?? null);
        $this->assertGreaterThanOrEqual(1, $body['retryAfterSeconds']);
    }

    public function testNonApiPathsBypassTheLimiterAndDoNotStampRateLimitHeaders(): void
    {
        // The listener is path-scoped to the `/api/` surface via ApiRequestMatcher. Even with the
        // budget pre-exhausted, a non-/api/ request must neither be rejected nor stamped — the
        // matcher early return at the top of onRequest() guarantees it.
        $kernelBrowser = self::createClient();
        $this->consumeTokens(self::REMOTE_ADDR_NON_API, self::BUDGET);

        $kernelBrowser->request(
            Request::METHOD_GET,
            '/non-api-path',
            server: ['REMOTE_ADDR' => self::REMOTE_ADDR_NON_API],
        );

        $response = $kernelBrowser->getResponse();
        // /non-api-path is unrouted — Symfony returns 404 — but crucially NOT a 429.
        $this->assertNotSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $response->getStatusCode(),
            'Non-/api/ requests must never be rate-limited.',
        );
        $this->assertNull($response->headers->get('RateLimit-Limit'));
        $this->assertNull($response->headers->get('X-RateLimit-Limit'));
        $this->assertNull($response->headers->get('Retry-After'));
    }

    public function testOptionsPreflightBypassesTheLimiterAndDoesNotStampRateLimitHeaders(): void
    {
        // CORS preflight (OPTIONS) requests must not consume budget. With the budget pre-
        // exhausted via direct factory probes, a fresh OPTIONS request must still avoid 429.
        // The listener's early return on Request::METHOD_OPTIONS guarantees both that no
        // token is consumed and that no snapshot is written — so the response listener
        // stamps no RateLimit-* headers either.
        $kernelBrowser = self::createClient();
        $this->consumeTokens(self::REMOTE_ADDR_OPTIONS, self::BUDGET);

        $kernelBrowser->request(
            Request::METHOD_OPTIONS,
            self::ENDPOINT,
            server: [
                'REMOTE_ADDR' => self::REMOTE_ADDR_OPTIONS,
                'HTTP_ORIGIN' => 'https://localhost',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => Request::METHOD_GET,
            ],
        );

        $response = $kernelBrowser->getResponse();
        $this->assertNotSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $response->getStatusCode(),
            'OPTIONS preflight must never be rate-limited.',
        );
        $this->assertNull($response->headers->get('RateLimit-Limit'));
        $this->assertNull($response->headers->get('X-RateLimit-Limit'));
        $this->assertNull($response->headers->get('Retry-After'));
    }

    /**
     * Pre-consume `$count` tokens for `$key` from the same `limiter.anonymous_api` factory
     * the listener resolves at request time. The factory autowire alias declared on
     * {@see RateLimitListener::__construct} pins the service id.
     */
    private function consumeTokens(string $key, int $count): void
    {
        /** @var RateLimiterFactoryInterface $factory */
        $factory = self::getContainer()->get('limiter.anonymous_api');
        $limiter = $factory->create($key);

        for ($i = 0; $i < $count; ++$i) {
            $limiter->consume(1);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(false|string|null $content): array
    {
        $this->assertIsString($content);
        $decoded = \json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
