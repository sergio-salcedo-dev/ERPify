<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener;

use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Integration test for the `ExceptionResponder` listener. Exercises the full pipeline through a
 * real Symfony kernel: request → routed test-only controller throws → listener catches → factory
 * resolves → responder builds the Problem Details response.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
#[CoversNothing]
final class ExceptionResponderFunctionalTest extends WebTestCase
{
    private const string UUID_V7_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const string VALID_UUID_V7 = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string THROW_NOT_FOUND_URI = '/api/test/_throw-not-found';

    private const string THROW_RUNTIME_URI = '/api/test/_throw-runtime';

    private const string PROBLEM_JSON_CONTENT_TYPE = 'application/problem+json';

    private const string BUFFER_NOT_EMPTY_MESSAGE = 'Buffer must start empty for this test.';

    public function testDomainExceptionMappedToProblemDetailsResponse(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, self::THROW_NOT_FOUND_URI);

        $response = $kernelBrowser->getResponse();

        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
        $this->assertSame(self::PROBLEM_JSON_CONTENT_TYPE, $response->headers->get('Content-Type'));

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $body = $this->decodeBody($response->getContent());

        $this->assertSame(
            ['type', 'title', 'status', 'instance', 'correlation-id', 'bankId', 'debug'],
            \array_keys($body),
            'Body key order must match the canonical contract '
            . '(`type, title, status, [detail], instance, correlation-id, <extensions>`); '
            . '`debug` is appended LAST in `<extensions>` for the `test` env.',
        );
        $this->assertBodyEquals('not-found', $body, 'type');
        $this->assertBodyEquals('Bank not found', $body, 'title');
        $this->assertBodyEquals(404, $body, 'status');
        $this->assertBodyEquals('01JABC', $body, 'bankId');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'instance');
    }

    public function testRuntimeExceptionMapsToFiveHundredUnhandledException(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, self::THROW_RUNTIME_URI);

        $response = $kernelBrowser->getResponse();

        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
        $this->assertSame(self::PROBLEM_JSON_CONTENT_TYPE, $response->headers->get('Content-Type'));

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyEquals('unhandled-exception', $body, 'type');
        $this->assertBodyEquals('boom', $body, 'title');
        $this->assertBodyEquals(500, $body, 'status');
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

    /**
     * @param array<string, mixed> $body
     */
    private function assertBodyEquals(mixed $expected, array $body, string $key): void
    {
        $this->assertArrayHasKey($key, $body);
        $this->assertSame($expected, $body[$key]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertBodyMatchesRegex(string $regex, array $body, string $key): void
    {
        $this->assertArrayHasKey($key, $body);
        $value = $body[$key];
        $this->assertIsString($value);
        $this->assertMatchesRegularExpression($regex, $value);
    }

    public function testBodyCorrelationIdEqualsResponseHeaderXCorrelationIdForErrorPath(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(
            Request::METHOD_GET,
            self::THROW_NOT_FOUND_URI,
            server: ['HTTP_X_CORRELATION_ID' => self::VALID_UUID_V7],
        );

        $response = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
        $this->assertSame(self::PROBLEM_JSON_CONTENT_TYPE, $response->headers->get('Content-Type'));

        $headerValue = $response->headers->get('X-Correlation-Id');
        $this->assertSame(self::VALID_UUID_V7, $headerValue);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyEquals($headerValue, $body, 'correlation-id');

        $this->assertArrayHasKey('instance', $body);
        $instance = $body['instance'];
        $this->assertIsString($instance);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $instance);
        $this->assertNotSame(
            $headerValue,
            $instance,
            'instance must be a fresh UUIDv7 per error occurrence, not the correlation-id.',
        );
    }

    public function testBodyCorrelationIdEqualsResponseHeaderXCorrelationIdWhenInboundAbsent(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, self::THROW_NOT_FOUND_URI);

        $response = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        $headerValue = $response->headers->get('X-Correlation-Id');
        $this->assertIsString($headerValue);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyEquals($headerValue, $body, 'correlation-id');
    }

    public function testTwoSequentialFailingRequestsWithSameInboundReceiveDistinctInstanceValues(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);

        $kernelBrowser->request(
            Request::METHOD_GET,
            self::THROW_NOT_FOUND_URI,
            server: ['HTTP_X_CORRELATION_ID' => self::VALID_UUID_V7],
        );
        $responseA = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $responseA->getStatusCode(),
            (string) $responseA->getContent(),
        );
        $this->assertSame(self::VALID_UUID_V7, $responseA->headers->get('X-Correlation-Id'));
        $bodyA = $this->decodeBody($responseA->getContent());
        $this->assertBodyEquals(self::VALID_UUID_V7, $bodyA, 'correlation-id');

        $kernelBrowser->request(
            Request::METHOD_GET,
            self::THROW_NOT_FOUND_URI,
            server: ['HTTP_X_CORRELATION_ID' => self::VALID_UUID_V7],
        );
        $responseB = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $responseB->getStatusCode(),
            (string) $responseB->getContent(),
        );
        $this->assertSame(self::VALID_UUID_V7, $responseB->headers->get('X-Correlation-Id'));
        $bodyB = $this->decodeBody($responseB->getContent());
        $this->assertBodyEquals(self::VALID_UUID_V7, $bodyB, 'correlation-id');

        $this->assertArrayHasKey('instance', $bodyA);
        $this->assertArrayHasKey('instance', $bodyB);
        $instanceA = $bodyA['instance'];
        $instanceB = $bodyB['instance'];
        $this->assertIsString($instanceA);
        $this->assertIsString($instanceB);
        $this->assertNotSame($instanceA, $instanceB, 'Each error occurrence must mint a distinct `instance` UUIDv7.');
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $instanceA);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $instanceB);
    }

    public function testRuntimeExceptionPathBodyCorrelationIdEqualsResponseHeader(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, self::THROW_RUNTIME_URI);

        $response = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        $headerValue = $response->headers->get('X-Correlation-Id');
        $this->assertIsString($headerValue);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyEquals($headerValue, $body, 'correlation-id');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'instance');
    }

    public function testFunctionalLogRecordIsEmittedAtLevelWarningForFourXxRoute(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);

        $bufferingLogger = $this->bufferingLogger();
        $this->assertCount(0, $bufferingLogger->cleanLogs(), self::BUFFER_NOT_EMPTY_MESSAGE);

        $kernelBrowser->request(Request::METHOD_GET, self::THROW_NOT_FOUND_URI);

        $response = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::WARNING, $logRecord['level']);
        $this->assertSame('API error response built', $logRecord['message']);
        $this->assertSame(404, $logRecord['context']['status'] ?? null);
        $this->assertSame('not-found', $logRecord['context']['type'] ?? null);
        $this->assertSame('GET', $logRecord['context']['request_method'] ?? null);
        $this->assertIsString($logRecord['context']['request_uri'] ?? null);
        $this->assertStringStartsWith(self::THROW_NOT_FOUND_URI, $logRecord['context']['request_uri']);
    }

    public function testFunctionalLogRecordIsEmittedAtLevelCriticalForUnhandledRuntimeRoute(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);

        $bufferingLogger = $this->bufferingLogger();
        $this->assertCount(0, $bufferingLogger->cleanLogs(), self::BUFFER_NOT_EMPTY_MESSAGE);

        $kernelBrowser->request(Request::METHOD_GET, self::THROW_RUNTIME_URI);

        $response = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::CRITICAL, $logRecord['level']);
        $this->assertSame(500, $logRecord['context']['status'] ?? null);
        $this->assertSame('unhandled-exception', $logRecord['context']['type'] ?? null);
        $this->assertSame('RuntimeException', $logRecord['context']['exception_class'] ?? null);
    }

    public function testFunctionalLogRecordIsEmittedAtLevelWarningForValidationFailedRoute(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);

        $bufferingLogger = $this->bufferingLogger();
        $this->assertCount(0, $bufferingLogger->cleanLogs(), self::BUFFER_NOT_EMPTY_MESSAGE);

        $kernelBrowser->request(Request::METHOD_GET, '/api/test/_throw-validation');

        $response = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::WARNING, $logRecord['level']);
        $this->assertSame(400, $logRecord['context']['status'] ?? null);
        $this->assertSame('validation-failed', $logRecord['context']['type'] ?? null);
    }

    public function testFunctionalLogRecordCorrelationIdEqualsBodyAndResponseHeader(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);

        $bufferingLogger = $this->bufferingLogger();
        $this->assertCount(0, $bufferingLogger->cleanLogs(), self::BUFFER_NOT_EMPTY_MESSAGE);

        $kernelBrowser->request(
            Request::METHOD_GET,
            self::THROW_NOT_FOUND_URI,
            server: ['HTTP_X_CORRELATION_ID' => self::VALID_UUID_V7],
        );

        $response = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        $headerValue = $response->headers->get('X-Correlation-Id');
        $body = $this->decodeBody($response->getContent());

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $logCorrelationId = $logRecord['context']['correlation_id'] ?? null;
        $logInstance = $logRecord['context']['instance'] ?? null;

        $this->assertSame(LogLevel::WARNING, $logRecord['level']);
        $this->assertSame(self::VALID_UUID_V7, $logCorrelationId);
        $this->assertSame($headerValue, $logCorrelationId);
        $this->assertSame($body['correlation-id'] ?? null, $logCorrelationId);
        $this->assertSame($body['instance'] ?? null, $logInstance);
    }

    public function testFunctionalNoLogRecordIsEmittedForHappyPathTwoHundred(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);

        $bufferingLogger = $this->bufferingLogger();
        $this->assertCount(0, $bufferingLogger->cleanLogs(), self::BUFFER_NOT_EMPTY_MESSAGE);

        $kernelBrowser->request(Request::METHOD_GET, '/api/v1/health');

        $response = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_OK,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        $this->assertCount(
            0,
            $bufferingLogger->cleanLogs(),
            'ExceptionResponder must NOT log on a happy-path 2xx response (listener is exception-only).',
        );
    }

    private function bufferingLogger(): BufferingLogger
    {
        $logger = self::getContainer()->get(BufferingLogger::class);
        $this->assertInstanceOf(BufferingLogger::class, $logger);

        return $logger;
    }

    /**
     * @return array{level: string, message: string, context: array<string,mixed>}
     */
    private function singleLogRecord(BufferingLogger $buffer): array
    {
        $logs = \array_values($buffer->cleanLogs());
        $this->assertCount(1, $logs, 'Listener must emit exactly one log record per failing request.');

        /** @var array{0: string, 1: string, 2: array<string,mixed>} $first */
        $first = $logs[0];
        [$level, $message, $context] = $first;

        return ['level' => $level, 'message' => $message, 'context' => $context];
    }

    public function testFunctionalTestEnvironmentBodyIncludesDebugExtension(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, self::THROW_RUNTIME_URI);

        $response = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        $body = $this->decodeBody($response->getContent());

        // `APP_ENV=test` (set by `make php.unit`) must wire `'test'` through the
        // `#[Autowire('%kernel.environment%')]` attribute on `ProblemDetailsFactory::__construct`,
        // so the body carries the full 5-key debug extension. This pins the autowire round-trip
        // end-to-end through a real Symfony kernel.
        $this->assertArrayHasKey('debug', $body);
        $debug = $body['debug'];
        $this->assertIsArray($debug);
        $this->assertSame(
            ['exception_class', 'message', 'file', 'line', 'previous_chain'],
            \array_keys($debug),
        );
        $this->assertArrayHasKey('exception_class', $debug);
        $this->assertArrayHasKey('message', $debug);
        $this->assertArrayHasKey('file', $debug);
        $this->assertArrayHasKey('line', $debug);
        $this->assertArrayHasKey('previous_chain', $debug);
        $this->assertSame('RuntimeException', $debug['exception_class']);
        $this->assertSame('boom', $debug['message']);
        $this->assertIsString($debug['file']);
        $this->assertIsInt($debug['line']);
        $this->assertSame([], $debug['previous_chain']);
    }

    public function testFunctionalTestEnvironmentDebugDoesNotSwapUnhandledExceptionTitle(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, self::THROW_RUNTIME_URI);

        $response = $kernelBrowser->getResponse();
        $body = $this->decodeBody($response->getContent());

        // In test env, the message-pass-through behaviour is preserved on the
        // unhandled-exception branch (only prod swaps the title to the safe literal). The
        // `'boom'` title here is the exception's own message.
        $this->assertArrayHasKey('title', $body);
        $this->assertSame('boom', $body['title']);

        // And the debug extension is still present alongside.
        $this->assertArrayHasKey('debug', $body);
    }

    public function testCorsHeadersSurviveOnErrorResponse(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        // Use a port-divergent allowed origin so Nelmio's `skip_same_as_origin` (default `true`)
        // does NOT short-circuit. The KernelBrowser's request scheme+host is `http://localhost`,
        // so `http://localhost:3000` is recognised as cross-origin yet still in the allowlist
        // configured by `CORS_ALLOW_ORIGINS` (see `api/config/packages/nelmio_cors.php`).
        $kernelBrowser->request(
            Request::METHOD_GET,
            self::THROW_NOT_FOUND_URI,
            server: [
                'HTTP_ORIGIN' => 'http://localhost:3000',
            ],
        );

        $response = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        // Light-touch sanity: with an allowed cross-origin set, Nelmio's response listener still
        // fires on the error response.
        // `ExceptionResponderListenerPriorityTest::testCrossOriginGetWithOriginHeaderReturnsBothCorsAndProblemDetails`
        // owns the strict regression pin; this assertion just guarantees the exception listener
        // does not break the CORS path on the long-standing fixture.
        $this->assertSame('http://localhost:3000', $response->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * Wire-level pin: the `_throw-denylisted-context` fixture's `password` and
     * `token` keys must be stripped from the body extensions; `safe_field` survives. The
     * `'sensitive'` value must not appear anywhere in the encoded response body.
     */
    public function testWireResponseStripsDenylistedKeysFromBodyExtensions(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, '/api/test/_throw-denylisted-context');

        $response = $kernelBrowser->getResponse();

        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
        $this->assertSame(self::PROBLEM_JSON_CONTENT_TYPE, $response->headers->get('Content-Type'));

        $body = $this->decodeBody($response->getContent());

        $this->assertArrayNotHasKey('password', $body, 'denylisted password key must be stripped from the wire body.');
        $this->assertArrayNotHasKey('token', $body, 'denylisted token key must be stripped from the wire body.');
        $this->assertArrayHasKey('safe_field', $body);
        $this->assertSame('kept', $body['safe_field']);

        $rawBody = (string) $response->getContent();
        $this->assertStringNotContainsString(
            'sensitive',
            $rawBody,
            'denylisted values must not appear anywhere in the encoded body.',
        );
    }

    /**
     * Wire-level pin: the `_throw-unserializable-context` fixture's `cb` (closure)
     * and `proxy` (stdClass) keys must be substituted with the literal `'[unserializable]'`
     * sentinel; `safe_field` survives. The body must contain no class name and no NUL byte
     * even when the context carries an anonymous-class object.
     */
    public function testWireResponseSubstitutesUnserializableValuesWithSentinel(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, '/api/test/_throw-unserializable-context');

        $response = $kernelBrowser->getResponse();

        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
        $this->assertSame(self::PROBLEM_JSON_CONTENT_TYPE, $response->headers->get('Content-Type'));

        $body = $this->decodeBody($response->getContent());

        $this->assertArrayHasKey('cb', $body);
        $this->assertArrayHasKey('proxy', $body);
        $this->assertSame(
            '[unserializable]',
            $body['cb'],
            'Closure context value must be substituted with the literal sentinel.',
        );
        $this->assertSame(
            '[unserializable]',
            $body['proxy'],
            'stdClass context value must be substituted with the literal sentinel.',
        );
        $this->assertArrayHasKey('safe_field', $body);
        $this->assertSame('kept', $body['safe_field'], 'Whitelisted scalar context values pass through unchanged.');

        $rawBody = (string) $response->getContent();
        $this->assertStringNotContainsString(
            'stdClass',
            $rawBody,
            'Wire body must not leak the original PHP class name (class names live in logs only).',
        );
        $this->assertStringNotContainsString(
            "\0",
            $rawBody,
            'Wire body must not contain a NUL byte from anonymous-class FQCNs.',
        );
    }

    /**
     * kernel.reset safety: two sequential requests against the SAME kernel
     * instance, with an explicit `$container->reset()` (the same machinery `kernel.reset`
     * triggers in FrankenPHP worker mode) between them, MUST produce structurally identical
     * Problem Details responses. The body shape (key set, `type`, `title`, `status`) and the
     * static response headers (`Content-Type`, `Cache-Control`) must be byte-for-byte equal;
     * only the per-error `instance` and `correlation-id` may differ.
     *
     * `disableReboot()` keeps the kernel container alive across the two `request()` calls so
     * the test can prove that the listener and factory hold no per-request state poisoning
     * the second response. The intermediate `Container::reset()` call exercises every
     * `ResetInterface`-tagged service (the `services_resetter` machinery) — exactly what
     * Symfony invokes between worker iterations.
     *
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     */
    public function testKernelResetBetweenSequentialRequestsProducesIdenticallyShapedProblemDetails(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->disableReboot();
        $kernelBrowser->catchExceptions(true);

        // Request 1 — capture the canonical body shape.
        $kernelBrowser->request(Request::METHOD_GET, self::THROW_NOT_FOUND_URI);

        $responseA = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $responseA->getStatusCode(),
            (string) $responseA->getContent(),
        );
        $bodyA = $this->decodeBody($responseA->getContent());

        // Capture static response-header invariants for the cross-request comparison. The
        // `Cache-Control` header carries Symfony's default flag set in addition to `no-store`;
        // the equality check below pins the full header string against drift.
        $contentTypeA = $responseA->headers->get('Content-Type');
        $cacheControlA = $responseA->headers->get('Cache-Control');

        // Mid-flight kernel.reset — exercises the ResetInterface chain that FrankenPHP
        // worker mode runs between requests. The container is fetched lazily so the second
        // request below picks up the post-reset state.
        $container = self::getContainer();
        $container->reset();

        // Request 2 — same route, same kernel.
        $kernelBrowser->request(Request::METHOD_GET, self::THROW_NOT_FOUND_URI);
        $responseB = $kernelBrowser->getResponse();
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $responseB->getStatusCode(),
            (string) $responseB->getContent(),
        );
        $bodyB = $this->decodeBody($responseB->getContent());

        // Header parity — the response shell is identical between the two requests.
        $this->assertSame($contentTypeA, $responseB->headers->get('Content-Type'));
        $this->assertSame($cacheControlA, $responseB->headers->get('Cache-Control'));

        // Body-key parity — same set of keys in the same order. Sequence drift here would
        // signal a stateful listener / factory leaking ordering across requests.
        $this->assertSame(
            \array_keys($bodyA),
            \array_keys($bodyB),
            'Body key set + order must be stable across kernel.reset.',
        );

        // Identical-shape fields — `type`, `title`, `status` and any domain-emitted
        // extension (here `bankId`) survive the reset unchanged.
        $this->assertSame($bodyA['type'] ?? null, $bodyB['type'] ?? null);
        $this->assertSame($bodyA['title'] ?? null, $bodyB['title'] ?? null);
        $this->assertSame($bodyA['status'] ?? null, $bodyB['status'] ?? null);
        $this->assertSame($bodyA['bankId'] ?? null, $bodyB['bankId'] ?? null);

        // Exclusivity — `instance` and `correlation-id` are the ONLY fields that may differ
        // (per-error and per-request mints respectively); compare every other key for
        // byte-for-byte equality so a future drift would surface here.
        foreach ($bodyA as $key => $valueA) {
            if ('instance' === $key) {
                continue;
            }

            if ('correlation-id' === $key) {
                continue;
            }

            $this->assertSame(
                $valueA,
                $bodyB[$key] ?? null,
                \sprintf('Body key "%s" must be identical across kernel.reset.', $key),
            );
        }

        // Distinctness — the per-error `instance` UUIDv7 mint MUST be fresh on each
        // invocation. Without this, two error occurrences would share an `instance` value,
        // breaking the per-error log-pivot contract.
        $this->assertArrayHasKey('instance', $bodyA);
        $this->assertArrayHasKey('instance', $bodyB);
        $this->assertNotSame($bodyA['instance'], $bodyB['instance']);
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $bodyA, 'instance');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $bodyB, 'instance');
    }

    /**
     * A `Doctrine\DBAL\Exception\ConnectionLost` thrown mid-controller MUST still produce a
     * conforming Problem Details 500. The error-path holds zero dependency on `Connection` /
     * `EntityManagerInterface` (pinned by reflection in `NoDatabaseDependenciesContractTest`);
     * the wire-level pin here proves no cascading failure escapes the listener even when the
     * underlying driver has dropped.
     */
    public function testDoctrineConnectionLostMidControllerProducesConformingProblemDetailsResponse(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, '/api/test/_throw-doctrine-connection-lost');

        $response = $kernelBrowser->getResponse();

        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
        $this->assertSame(self::PROBLEM_JSON_CONTENT_TYPE, $response->headers->get('Content-Type'));

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $body = $this->decodeBody($response->getContent());

        // Default-deny on unknown exception — `ConnectionLost` is neither a DomainException
        // nor a Symfony bridge case, so the factory's terminal branch lands on
        // `unhandled-exception` / 500.
        $this->assertBodyEquals('unhandled-exception', $body, 'type');
        $this->assertBodyEquals(500, $body, 'status');

        // The instance + correlation-id mints succeeded — proves the listener completed its
        // primary path (no last-resort static body, which would lack both fields).
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'instance');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id');
    }
}
