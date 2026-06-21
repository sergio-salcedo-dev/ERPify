<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Http\EventListener;

use BadMethodCallException;
use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;
use Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder;
use Erpify\Shared\Search\Domain\Exception\InvalidCursor;
use Erpify\Shared\Search\Infrastructure\Http\EventListener\SearchObservabilityListener;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(SearchObservabilityListener::class)]
final class SearchObservabilityListenerTest extends TestCase
{
    private const string SEARCH_ROUTE = 'backoffice_bank_search';

    private const string CORRELATION_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string FULL_PAGE_BODY
        = '{"data":[],"pagination":{"hasNext":true,"hasPrev":false,"count":31,"links":{"next":"/x","prev":null}}}';

    private const string LONE_PAGE_BODY
        = '{"data":[],"pagination":{"hasNext":false,"hasPrev":false,"count":null,"links":{"next":null,"prev":null}}}';

    public function testEmitsKeysetSearchEventOnSuccessfulSearchResponse(): void
    {
        $logger = new BufferingLogger();
        (new SearchObservabilityListener($logger))->onResponse($this->responseEvent(
            query: 'limit=5&after=opaque-cursor&paginationMode=detailed',
            body: self::FULL_PAGE_BODY,
        ));

        [$level, $message, $context] = $this->singleLog($logger);
        $this->assertSame('info', $level);
        $this->assertSame('keyset_search', $message);
        $this->assertSame([
            'event' => 'keyset_search',
            'route' => self::SEARCH_ROUTE,
            'limit' => 5,
            'direction' => 'next',
            'pagination_mode' => 'detailed',
            'count_mode' => 'DETAILED',
            'has_next' => true,
            'has_prev' => false,
            'correlation_id' => self::CORRELATION_ID,
        ], $context);
    }

    #[DataProvider('provideDirectionAndDefaultsAreDerivedFromTheQueryCases')]
    public function testDirectionAndDefaultsAreDerivedFromTheQuery(
        string $query,
        string $expectedDirection,
        int $expectedLimit,
    ): void {
        $logger = new BufferingLogger();
        (new SearchObservabilityListener($logger))->onResponse($this->responseEvent(
            query: $query,
            body: self::LONE_PAGE_BODY,
        ));

        [, , $context] = $this->singleLog($logger);
        $this->assertSame([
            'event' => 'keyset_search',
            'route' => self::SEARCH_ROUTE,
            'limit' => $expectedLimit,
            'direction' => $expectedDirection,
            'pagination_mode' => 'light',
            'count_mode' => 'LIGHT',
            'has_next' => false,
            'has_prev' => false,
            'correlation_id' => self::CORRELATION_ID,
        ], $context);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideDirectionAndDefaultsAreDerivedFromTheQueryCases(): iterable
    {
        yield 'after → next' => ['limit=5&after=c', 'next', 5];
        yield 'before → prev' => ['limit=5&before=c', 'prev', 5];
        // An omitted limit reports the effective default (25), not null; neither cursor → first page.
        yield 'neither → first page, default limit' => ['', 'first', 25];
    }

    public function testIgnoresNonSearchRoute(): void
    {
        $logger = new BufferingLogger();
        (new SearchObservabilityListener($logger))->onResponse(
            $this->responseEvent(query: '', body: '{"data":{}}', route: 'backoffice_bank_get'),
        );

        $this->assertSame([], $logger->cleanLogs());
    }

    public function testIgnoresSubRequest(): void
    {
        $logger = new BufferingLogger();
        (new SearchObservabilityListener($logger))->onResponse(
            $this->responseEvent(query: '', body: '{"data":[],"pagination":{}}', main: false),
        );

        $this->assertSame([], $logger->cleanLogs());
    }

    public function testIgnoresUnsuccessfulResponse(): void
    {
        $logger = new BufferingLogger();
        (new SearchObservabilityListener($logger))->onResponse(
            $this->responseEvent(
                query: '',
                body: '{"type":"invalid-cursor"}',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            ),
        );

        $this->assertSame([], $logger->cleanLogs());
    }

    public function testStaysSilentWhenBodyHasNoPaginationEnvelope(): void
    {
        $logger = new BufferingLogger();
        (new SearchObservabilityListener($logger))->onResponse(
            $this->responseEvent(query: '', body: 'not-json'),
        );

        $this->assertSame([], $logger->cleanLogs());
    }

    public function testEmitsInvalidCursorEventWithCause(): void
    {
        $logger = new BufferingLogger();
        (new SearchObservabilityListener($logger))->onException(
            $this->exceptionEvent(InvalidCursor::version()),
        );

        [$level, $message, $context] = $this->singleLog($logger);
        $this->assertSame('info', $level);
        $this->assertSame('invalid_cursor', $message);
        $this->assertSame([
            'event' => 'invalid_cursor',
            'cursor_cause' => 'version',
            'route' => self::SEARCH_ROUTE,
            'correlation_id' => self::CORRELATION_ID,
        ], $context);
    }

    public function testUnwrapsInvalidCursorNestedInTheThrowableChain(): void
    {
        $logger = new BufferingLogger();
        $wrapped = new RuntimeException('framework wrapper', 0, InvalidCursor::signature());

        (new SearchObservabilityListener($logger))->onException($this->exceptionEvent($wrapped));

        [, $message, $context] = $this->singleLog($logger);
        $this->assertSame('invalid_cursor', $message);
        $this->assertSame('signature', $context['cursor_cause'] ?? null);
    }

    public function testIgnoresExceptionsThatAreNotInvalidCursor(): void
    {
        $logger = new BufferingLogger();
        (new SearchObservabilityListener($logger))->onException(
            $this->exceptionEvent(new RuntimeException('unrelated')),
        );

        $this->assertSame([], $logger->cleanLogs());
    }

    /**
     * Regression guard for the stopPropagation trap: `ExceptionEvent` extends `RequestEvent`, so
     * once `ExceptionResponder` calls `setResponse()` propagation halts. The observability listener
     * MUST therefore run at a higher priority than the responder, or `invalid_cursor` is silently
     * never emitted.
     */
    public function testOnExceptionRunsBeforeTheProblemDetailsResponder(): void
    {
        $attributes = (new ReflectionMethod(SearchObservabilityListener::class, 'onException'))
            ->getAttributes(AsEventListener::class)
        ;

        $this->assertCount(1, $attributes);
        $this->assertGreaterThan(ExceptionResponder::PRIORITY, $attributes[0]->newInstance()->priority);
    }

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function responseEvent(
        string $query,
        string $body,
        string $route = self::SEARCH_ROUTE,
        int $status = Response::HTTP_OK,
        bool $main = true,
    ): ResponseEvent {
        $request = $this->request($query, $route);

        return new ResponseEvent(
            $this->kernel(),
            $request,
            $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
            new Response($body, $status, ['Content-Type' => 'application/json']),
        );
    }

    private function exceptionEvent(Throwable $throwable): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->kernel(),
            $this->request('', self::SEARCH_ROUTE),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }

    private function request(string $query, string $route): Request
    {
        $request = Request::create('/api/v1/backoffice/banks?' . $query);
        $request->attributes->set('_route', $route);
        $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, self::CORRELATION_ID);

        return $request;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            #[Override]
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                throw new BadMethodCallException('Test kernel: handle() must not be called by the listener.');
            }
        };
    }

    /**
     * @return array{string, string, array<string, mixed>}
     */
    private function singleLog(BufferingLogger $logger): array
    {
        $logs = \array_values($logger->cleanLogs());
        $this->assertCount(1, $logs, 'Exactly one observability line expected.');

        /** @phpstan-var array{string, string, array<string, mixed>} */
        return $logs[0];
    }
}
