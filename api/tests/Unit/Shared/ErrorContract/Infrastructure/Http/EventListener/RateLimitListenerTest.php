<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\ErrorContract\Infrastructure\Http\EventListener;

use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\RateLimitListener;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * @internal
 */
#[CoversClass(RateLimitListener::class)]
final class RateLimitListenerTest extends TestCase
{
    private const int TEST_LIMIT = 3;

    private const string CLIENT_IP = '203.0.113.42';

    public function testAcceptsRequestUnderBudgetAndStampsHeaders(): void
    {
        $rateLimitListener = $this->makeListener();
        $requestEvent = $this->makeRequestEvent('/api/v1/anything', self::CLIENT_IP);

        $rateLimitListener->onRequest($requestEvent);

        $responseEvent = $this->makeResponseEvent($requestEvent->getRequest());
        $rateLimitListener->onResponse($responseEvent);

        $headers = $responseEvent->getResponse()->headers;
        $this->assertSame((string) self::TEST_LIMIT, $headers->get('RateLimit-Limit'));
        $this->assertSame((string) (self::TEST_LIMIT - 1), $headers->get('RateLimit-Remaining'));
        $this->assertSame((string) self::TEST_LIMIT, $headers->get('X-RateLimit-Limit'));
        $this->assertSame((string) (self::TEST_LIMIT - 1), $headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($headers->get('RateLimit-Reset'));
        $this->assertNotNull($headers->get('X-RateLimit-Reset'));
        $this->assertNull($headers->get('Retry-After'), 'Retry-After must only ship on the rejected path.');
    }

    public function testThrowsRateLimitExceededOnceBudgetIsExhausted(): void
    {
        $rateLimitListener = $this->makeListener();

        for ($i = 0; $i < self::TEST_LIMIT; ++$i) {
            $rateLimitListener->onRequest($this->makeRequestEvent('/api/v1/anything', self::CLIENT_IP));
        }

        // overflowEvent
        $requestEvent = $this->makeRequestEvent('/api/v1/anything', self::CLIENT_IP);

        try {
            $rateLimitListener->onRequest($requestEvent);
            $this->fail('Listener must throw RateLimitExceeded once the budget is exhausted.');
        } catch (RateLimitExceeded $rateLimitExceeded) {
            $this->assertSame(self::TEST_LIMIT, $rateLimitExceeded->limit);
            $this->assertSame(0, $rateLimitExceeded->remaining);
            $this->assertGreaterThanOrEqual(1, $rateLimitExceeded->retryAfterSeconds);
            $this->assertSame(self::CLIENT_IP, $rateLimitExceeded->limiterKey);
        }

        // The rejection is still recorded for the response: the throw happens after the stamp, which is what
        // lets the 429 carry its own drained budget instead of the numbers of the request before it.
        $responseEvent = $this->makeResponseEvent($requestEvent->getRequest(), Response::HTTP_TOO_MANY_REQUESTS);
        $rateLimitListener->onResponse($responseEvent);

        $this->assertSame('0', $responseEvent->getResponse()->headers->get('RateLimit-Remaining'));
    }

    public function testRejectedResponseCarriesRetryAfterHeader(): void
    {
        $rateLimitListener = $this->makeListener();

        for ($i = 0; $i < self::TEST_LIMIT; ++$i) {
            $rateLimitListener->onRequest($this->makeRequestEvent('/api/v1/anything', self::CLIENT_IP));
        }

        // overflowEvent
        $requestEvent = $this->makeRequestEvent('/api/v1/anything', self::CLIENT_IP);

        try {
            $rateLimitListener->onRequest($requestEvent);
        } catch (RateLimitExceeded) {
            // Expected — snapshot was stamped before the throw so the response listener can
            // still read it from the same request attribute.
        }

        $responseEvent = $this->makeResponseEvent($requestEvent->getRequest(), Response::HTTP_TOO_MANY_REQUESTS);
        $rateLimitListener->onResponse($responseEvent);

        $headers = $responseEvent->getResponse()->headers;
        $this->assertSame('0', $headers->get('RateLimit-Remaining'));
        $this->assertSame('0', $headers->get('X-RateLimit-Remaining'));
        $retryAfter = $headers->get('Retry-After');
        $this->assertNotNull($retryAfter);
        $this->assertGreaterThanOrEqual(1, (int) $retryAfter);
    }

    public function testSkipsNonApiPaths(): void
    {
        $rateLimitListener = $this->makeListener();
        $requestEvent = $this->makeRequestEvent('/_profiler/something', self::CLIENT_IP);

        $rateLimitListener->onRequest($requestEvent);

        $responseEvent = $this->makeResponseEvent($requestEvent->getRequest());
        $rateLimitListener->onResponse($responseEvent);

        $this->assertNull(
            $responseEvent->getResponse()->headers->get('RateLimit-Limit'),
            'A non-/api/ path must reach the response with no rate-limit headers.',
        );
    }

    public function testSkipsOptionsPreflightRequests(): void
    {
        $rateLimitListener = $this->makeListener();
        $request = Request::create('/api/v1/anything', Request::METHOD_OPTIONS);
        $request->server->set('REMOTE_ADDR', self::CLIENT_IP);

        $requestEvent = new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $rateLimitListener->onRequest($requestEvent);

        $responseEvent = $this->makeResponseEvent($requestEvent->getRequest());
        $rateLimitListener->onResponse($responseEvent);

        $this->assertNull(
            $responseEvent->getResponse()->headers->get('RateLimit-Limit'),
            'OPTIONS preflights must not consume the per-IP budget (CorsListener short-circuits them).',
        );
    }

    public function testSkipsSubRequests(): void
    {
        $rateLimitListener = $this->makeListener();
        $httpKernel = $this->makeKernel();
        $request = Request::create('/api/v1/anything');
        $request->server->set('REMOTE_ADDR', self::CLIENT_IP);

        $requestEvent = new RequestEvent($httpKernel, $request, HttpKernelInterface::SUB_REQUEST);

        $rateLimitListener->onRequest($requestEvent);

        $responseEvent = $this->makeResponseEvent($request);
        $rateLimitListener->onResponse($responseEvent);

        $this->assertNull(
            $responseEvent->getResponse()->headers->get('RateLimit-Limit'),
            'Sub-requests must not consume the per-IP budget.',
        );
    }

    public function testIsolatesClientIpsIntoSeparateBuckets(): void
    {
        $rateLimitListener = $this->makeListener();

        for ($i = 0; $i < self::TEST_LIMIT; ++$i) {
            $rateLimitListener->onRequest($this->makeRequestEvent('/api/v1/anything', '198.51.100.7'));
        }

        // A different client IP must still have its full budget after the first IP exhausts.
        $freshClientRequestEvent = $this->makeRequestEvent('/api/v1/anything', '198.51.100.8');
        $rateLimitListener->onRequest($freshClientRequestEvent);

        $responseEvent = $this->makeResponseEvent($freshClientRequestEvent->getRequest());
        $rateLimitListener->onResponse($responseEvent);

        $headers = $responseEvent->getResponse()->headers;
        $this->assertSame((string) (self::TEST_LIMIT - 1), $headers->get('RateLimit-Remaining'));
        $this->assertNull($headers->get('Retry-After'), 'The fresh client was admitted, not refused.');
    }

    public function testResponseListenerSkipsWhenSnapshotMissing(): void
    {
        $rateLimitListener = $this->makeListener();
        $responseEvent = $this->makeResponseEvent(Request::create('/anything'));

        $rateLimitListener->onResponse($responseEvent);

        $this->assertNull($responseEvent->getResponse()->headers->get('RateLimit-Limit'));
        $this->assertNull($responseEvent->getResponse()->headers->get('X-RateLimit-Limit'));
    }

    public function testListenerPrioritiesArePinned(): void
    {
        // @phpstan-ignore method.alreadyNarrowedType (the assertion IS the test — pins the constant baseline)
        $this->assertSame(512, RateLimitListener::REQUEST_PRIORITY);
        // @phpstan-ignore method.alreadyNarrowedType (the assertion IS the test — pins the constant baseline)
        $this->assertSame(RateLimitListener::RESPONSE_PRIORITY, -128);
    }

    public function testListenerIsFinalReadonlyAndHasOnlyInjectedDependencies(): void
    {
        $reflectionClass = new ReflectionClass(RateLimitListener::class);

        $this->assertTrue($reflectionClass->isFinal());
        $this->assertTrue($reflectionClass->isReadOnly());

        $parameters = $reflectionClass->getConstructor()?->getParameters() ?? [];
        $this->assertNotEmpty($parameters);

        foreach ($parameters as $parameter) {
            $this->assertTrue(
                $parameter->isPromoted(),
                'Constructor params must be promoted to satisfy the worker-mode pattern.',
            );
        }
    }

    private function makeListener(): RateLimitListener
    {
        $rateLimiterFactory = new RateLimiterFactory(
            [
                'id' => 'test_anonymous_api',
                'policy' => 'sliding_window',
                'limit' => self::TEST_LIMIT,
                'interval' => '1 minute',
            ],
            new InMemoryStorage(),
        );

        return new RateLimitListener($rateLimiterFactory, new ApiRequestMatcher());
    }

    private function makeRequestEvent(string $path, string $clientIp): RequestEvent
    {
        $request = Request::create($path);
        $request->server->set('REMOTE_ADDR', $clientIp);

        return new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeResponseEvent(Request $request, int $status = Response::HTTP_OK): ResponseEvent
    {
        return new ResponseEvent(
            $this->makeKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response('ok', $status),
        );
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function makeKernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            #[Override]
            public function handle(
                Request $request,
                int $type = HttpKernelInterface::MAIN_REQUEST,
                bool $catch = true,
            ): Response {
                throw new LogicException('Test kernel: handle() must not be called by the listener.');
            }
        };
    }
}
