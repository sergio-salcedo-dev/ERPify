<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Http\EventListener;

use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\NotFound;
use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;
use Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder;
use Erpify\Shared\Infrastructure\Http\ProblemDetailsResponder;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(ExceptionResponder::class)]
final class ExceptionResponderTest extends TestCase
{
    private const string UUID_V7_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const string VALID_UUID_V7 = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    public function testReturnsEarlyWhenResponseAlreadySetByEarlierListener(): void
    {
        $exceptionResponder = $this->makeListener();
        $exceptionEvent = $this->makeEvent('/api/v1/anything', new RuntimeException('boom'));
        $exceptionEvent->setResponse(new Response('preset', Response::HTTP_I_AM_A_TEAPOT));

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_I_AM_A_TEAPOT, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('preset', $response->getContent());
    }

    public function testReturnsEarlyForNonApiPath(): void
    {
        $exceptionResponder = $this->makeListener();
        $exceptionEvent = $this->makeEvent('/_profiler/something', new RuntimeException('boom'));

        $exceptionResponder($exceptionEvent);

        $this->assertFalse($exceptionEvent->hasResponse(), 'Listener must not act on paths outside /api/.');
    }

    public function testDomainExceptionMappedToProblemDetailsResponse(): void
    {
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'Bank not found', ['bank_id' => '01JABC']) extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $body = $this->decodeBody($response->getContent());

        $this->assertBodyEquals('not-found', $body, 'type');
        $this->assertBodyEquals('Bank not found', $body, 'title');
        $this->assertBodyEquals(404, $body, 'status');
        $this->assertBodyEquals('01JABC', $body, 'bank_id');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'instance');
    }

    public function testCorrelationIdEchoesRequestAttributeWhenAttributeIsValidUuidV7(): void
    {
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'x') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
        $exceptionEvent->getRequest()->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, self::VALID_UUID_V7);

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyEquals(self::VALID_UUID_V7, $body, 'correlation-id');
    }

    public function testCorrelationIdMintedAsUuidV7WhenAttributeMissing(): void
    {
        // Story 2.1 contractually populates the `_correlation_id` request attribute on every
        // main /api/* request. This test pins the defense-in-depth fallback per Story 2.2's
        // onResponse pattern: if the attribute is missing (sub-request, future listener
        // tampering, etc.), the listener mints a fresh canonical lowercase UUIDv7.
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'x') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id');
    }

    public function testCorrelationIdMintedAsUuidV7WhenAttributeIsNonString(): void
    {
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'x') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
        $exceptionEvent->getRequest()->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, 12345);

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id');
        $this->assertNotSame('12345', $body['correlation-id'] ?? null);
    }

    public function testRuntimeExceptionMapsToFiveHundredUnhandledException(): void
    {
        $exceptionResponder = $this->makeListener();
        $exceptionEvent = $this->makeEvent('/api/v1/anything', new RuntimeException('boom'));

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode(), (string) $response->getContent());

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyEquals('unhandled-exception', $body, 'type');
        $this->assertBodyEquals('boom', $body, 'title');
    }

    public function testInstanceIsFreshUuidV7PerInvocation(): void
    {
        $exceptionResponder = $this->makeListener();

        $exceptionEvent = $this->makeEvent('/api/v1/x', new RuntimeException('a'));
        $exceptionResponder($exceptionEvent);
        $bodyA = $this->decodeBody($exceptionEvent->getResponse()?->getContent());

        $eventB = $this->makeEvent('/api/v1/x', new RuntimeException('b'));
        $exceptionResponder($eventB);
        $bodyB = $this->decodeBody($eventB->getResponse()?->getContent());

        $this->assertArrayHasKey('instance', $bodyA);
        $this->assertArrayHasKey('instance', $bodyB);
        $this->assertNotSame($bodyA['instance'], $bodyB['instance'], 'Listener must mint a fresh `instance` UUIDv7 per error occurrence.');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $bodyA, 'instance');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $bodyB, 'instance');
    }

    public function testInstanceIsFreshUuidV7AndDistinctFromCorrelationIdWithinSameRequest(): void
    {
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'Bank not found') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
        $exceptionEvent->getRequest()->attributes->set(
            CorrelationIdListener::ATTRIBUTE_KEY,
            self::VALID_UUID_V7,
        );

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);

        $body = $this->decodeBody($response->getContent());

        $this->assertBodyEquals(self::VALID_UUID_V7, $body, 'correlation-id');
        $this->assertArrayHasKey('instance', $body);
        $instance = $body['instance'];
        $this->assertIsString($instance);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $instance);
        $this->assertNotSame(
            self::VALID_UUID_V7,
            $instance,
            'instance must be minted fresh per error, distinct from correlation-id.',
        );
    }

    public function testCorrelationIdRemintedWhenAttributeIsUppercase(): void
    {
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'x') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
        $exceptionEvent->getRequest()->attributes->set(
            CorrelationIdListener::ATTRIBUTE_KEY,
            '0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C',
        );

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id');
        $this->assertNotSame('0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C', $body['correlation-id'] ?? null);
    }

    public function testCorrelationIdRemintedWhenAttributeContainsEmbeddedNewline(): void
    {
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'x') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
        $exceptionEvent->getRequest()->attributes->set(
            CorrelationIdListener::ATTRIBUTE_KEY,
            "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c\nX-Forwarded-For: evil",
        );

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id');
        $correlationId = $body['correlation-id'] ?? null;
        $this->assertIsString($correlationId);
        $this->assertStringNotContainsString("\n", $correlationId);
    }

    public function testCorrelationIdRemintedWhenAttributeIsLengthMismatch(): void
    {
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'x') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
        $exceptionEvent->getRequest()->attributes->set(
            CorrelationIdListener::ATTRIBUTE_KEY,
            '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2',
        );

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id');
        $this->assertNotSame('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2', $body['correlation-id'] ?? null);
    }

    public function testEachInvocationMintsADistinctInstanceUuidV7(): void
    {
        $exceptionResponder = $this->makeListener();

        $exceptionEvent = $this->makeEvent('/api/v1/x', new RuntimeException('a'));
        $exceptionEvent->getRequest()->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, self::VALID_UUID_V7);
        $exceptionResponder($exceptionEvent);
        $bodyA = $this->decodeBody($exceptionEvent->getResponse()?->getContent());

        $eventB = $this->makeEvent('/api/v1/x', new RuntimeException('b'));
        $eventB->getRequest()->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, self::VALID_UUID_V7);
        $exceptionResponder($eventB);
        $bodyB = $this->decodeBody($eventB->getResponse()?->getContent());

        $this->assertBodyEquals(self::VALID_UUID_V7, $bodyA, 'correlation-id');
        $this->assertBodyEquals(self::VALID_UUID_V7, $bodyB, 'correlation-id');
        $this->assertArrayHasKey('instance', $bodyA);
        $this->assertArrayHasKey('instance', $bodyB);
        $this->assertNotSame($bodyA['instance'], $bodyB['instance']);
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $bodyA, 'instance');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $bodyB, 'instance');
    }

    public function testLogRecordIsEmittedWithLevelWarningForDomainExceptionWithFourXxMarker(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exception = new class ('', 'Bank not found') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/banks/01-XYZ', $exception);
        $exceptionEvent->getRequest()->attributes->set(
            CorrelationIdListener::ATTRIBUTE_KEY,
            self::VALID_UUID_V7,
        );

        $exceptionResponder($exceptionEvent);

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::WARNING, $logRecord['level']);
        $this->assertSame('API error response built', $logRecord['message']);

        $context = $logRecord['context'];
        $this->assertSame(self::VALID_UUID_V7, $context['correlation_id']);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $context['instance']);
        $this->assertSame('not-found', $context['type']);
        $this->assertSame(404, $context['status']);
        $this->assertStringContainsString(
            '@anonymous',
            $context['exception_class'],
            'Anonymous-class FQCN convention preserved (PHP emits e.g. `DomainException@anonymous\0/path:line$N`).',
        );
        $this->assertSame('Bank not found', $context['exception_message']);
        $this->assertSame('/api/v1/banks/01-XYZ', $context['request_uri']);
        $this->assertSame('GET', $context['request_method']);
    }

    public function testLogRecordContextFieldsAreInDeclarationOrderAndCorrectlyTyped(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exception = new class ('', 'x') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
        $exceptionEvent->getRequest()->attributes->set(
            CorrelationIdListener::ATTRIBUTE_KEY,
            self::VALID_UUID_V7,
        );

        $exceptionResponder($exceptionEvent);

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $context = $logRecord['context'];

        // Re-widen so PHPStan does not consider the array_keys order check tautological under
        // the helper's narrow @return shape. The runtime check is the actual contract pin —
        // a listener that drifted from the declared order would only be caught here.
        /** @var array<string,mixed> $looseContext */
        $looseContext = $context;

        $this->assertSame(
            ['instance', 'correlation_id', 'type', 'status', 'exception_class', 'exception_message', 'request_uri', 'request_method'],
            \array_keys($looseContext),
            'Context keys must appear in FR32 declaration order.',
        );
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $context['instance']);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $context['correlation_id']);
        $this->assertStringStartsWith('/api/', $context['request_uri']);
        $this->assertMatchesRegularExpression('/^[A-Z]+$/', $context['request_method']);
    }

    public function testLogRecordIsEmittedWithLevelErrorForPlainDomainExceptionMappedToFiveHundred(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exception = new class ('', 'plain domain failure') extends DomainException {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);

        $exceptionResponder($exceptionEvent);

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::ERROR, $logRecord['level']);
        $this->assertSame(500, $logRecord['context']['status']);
        $this->assertSame('domain-error', $logRecord['context']['type']);
        $this->assertSame('plain domain failure', $logRecord['context']['exception_message']);
    }

    public function testLogRecordIsEmittedWithLevelErrorForFiveHundredHttpException(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exceptionEvent = $this->makeEvent(
            '/api/v1/anything',
            new HttpException(503, 'maintenance window'),
        );

        $exceptionResponder($exceptionEvent);

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::ERROR, $logRecord['level']);
        $this->assertSame(503, $logRecord['context']['status']);
        $this->assertSame('http-error', $logRecord['context']['type']);
        $this->assertSame(HttpException::class, $logRecord['context']['exception_class']);
        $this->assertSame('maintenance window', $logRecord['context']['exception_message']);
    }

    public function testLogRecordIsEmittedWithLevelCriticalForUnhandledRuntimeException(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exceptionEvent = $this->makeEvent('/api/v1/anything', new RuntimeException('boom'));

        $exceptionResponder($exceptionEvent);

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::CRITICAL, $logRecord['level']);
        $this->assertSame(500, $logRecord['context']['status']);
        $this->assertSame('unhandled-exception', $logRecord['context']['type']);
        $this->assertSame(RuntimeException::class, $logRecord['context']['exception_class']);
        $this->assertSame('boom', $logRecord['context']['exception_message']);
    }

    public function testLogRecordIsEmittedWithLevelWarningForValidationFailedException(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('msg', null, [], '', 'name', null),
        ]);
        $exceptionEvent = $this->makeEvent(
            '/api/v1/anything',
            new ValidationFailedException('payload', $constraintViolationList),
        );

        $exceptionResponder($exceptionEvent);

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::WARNING, $logRecord['level']);
        $this->assertSame(422, $logRecord['context']['status']);
        $this->assertSame('validation-failed', $logRecord['context']['type']);
        $this->assertSame(ValidationFailedException::class, $logRecord['context']['exception_class']);
    }

    public function testLogRecordIsEmittedWithLevelWarningForAccessDeniedException(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exceptionEvent = $this->makeEvent(
            '/api/v1/anything',
            new AccessDeniedException('Access denied.'),
        );

        $exceptionResponder($exceptionEvent);

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::WARNING, $logRecord['level']);
        $this->assertSame(403, $logRecord['context']['status']);
        $this->assertSame('forbidden', $logRecord['context']['type']);
        $this->assertSame(AccessDeniedException::class, $logRecord['context']['exception_class']);
    }

    public function testLogRecordIsEmittedWithLevelWarningForAuthenticationException(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exceptionEvent = $this->makeEvent(
            '/api/v1/anything',
            new BadCredentialsException('Bad creds'),
        );

        $exceptionResponder($exceptionEvent);

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::WARNING, $logRecord['level']);
        $this->assertSame(401, $logRecord['context']['status']);
        $this->assertSame('unauthenticated', $logRecord['context']['type']);
        $this->assertSame(BadCredentialsException::class, $logRecord['context']['exception_class']);
    }

    public function testLogRecordIsEmittedWithLevelWarningForFourXxHttpException(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exceptionEvent = $this->makeEvent(
            '/api/v1/anything',
            new HttpException(410, 'gone'),
        );

        $exceptionResponder($exceptionEvent);

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::WARNING, $logRecord['level']);
        $this->assertSame(410, $logRecord['context']['status']);
        $this->assertSame('http-error', $logRecord['context']['type']);
        $this->assertSame(HttpException::class, $logRecord['context']['exception_class']);
        $this->assertSame('gone', $logRecord['context']['exception_message']);
    }

    public function testNoLogRecordIsEmittedWhenResponseAlreadySetByEarlierListener(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exceptionEvent = $this->makeEvent('/api/v1/anything', new RuntimeException('boom'));
        $exceptionEvent->setResponse(new Response('preset', Response::HTTP_I_AM_A_TEAPOT));

        $exceptionResponder($exceptionEvent);

        $this->assertCount(
            0,
            $bufferingLogger->cleanLogs(),
            'Listener must NOT log when an earlier listener already set the response (AC #11).',
        );
    }

    public function testNoLogRecordIsEmittedForNonApiPath(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exceptionEvent = $this->makeEvent('/_profiler/something', new RuntimeException('boom'));

        $exceptionResponder($exceptionEvent);

        $this->assertCount(
            0,
            $bufferingLogger->cleanLogs(),
            'Listener must NOT log for paths outside /api/ (AC #11).',
        );
    }

    public function testLogRecordCorrelationIdAndInstanceMatchTheBodyEquivalents(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exception = new class ('', 'Bank not found') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
        $exceptionEvent->getRequest()->attributes->set(
            CorrelationIdListener::ATTRIBUTE_KEY,
            self::VALID_UUID_V7,
        );

        $exceptionResponder($exceptionEvent);

        $body = $this->decodeBody($exceptionEvent->getResponse()?->getContent());
        $logRecord = $this->singleLogRecord($bufferingLogger);

        $this->assertSame(self::VALID_UUID_V7, $logRecord['context']['correlation_id']);
        $this->assertSame(
            $body['correlation-id'] ?? null,
            $logRecord['context']['correlation_id'],
            'Log correlation_id must equal body correlation-id (FR48 — operator pivot parity).',
        );
        $this->assertSame(
            $body['instance'] ?? null,
            $logRecord['context']['instance'],
            'Log instance must equal body instance (FR48 — operator pivot parity).',
        );
    }

    public function testListenerImportsCorrelationIdListenerOnlyForAttributeKeyConstant(): void
    {
        $sourcePath = \dirname(__DIR__, 6) . '/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php';
        $this->assertFileExists($sourcePath);

        $contents = \file_get_contents($sourcePath);
        $this->assertNotFalse($contents);

        $this->assertSame(
            1,
            \substr_count($contents, 'use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;'),
            'ExceptionResponder.php must import CorrelationIdListener exactly once.',
        );
        $this->assertGreaterThanOrEqual(
            1,
            \substr_count($contents, 'CorrelationIdListener::ATTRIBUTE_KEY'),
            'ExceptionResponder.php must reference CorrelationIdListener::ATTRIBUTE_KEY at least once.',
        );
        $this->assertStringNotContainsString(
            "'correlation-id'",
            $contents,
            'ExceptionResponder.php must not reference the legacy kebab-case attribute key.',
        );
    }

    public function testListenerRegistrationAttributeIsKernelExceptionEvent(): void
    {
        $reflectionClass = new ReflectionClass(ExceptionResponder::class);
        $attributes = $reflectionClass->getAttributes(\Symfony\Component\EventDispatcher\Attribute\AsEventListener::class);

        $this->assertCount(1, $attributes, 'ExceptionResponder must declare exactly one #[AsEventListener] attribute.');

        $arguments = $attributes[0]->getArguments();
        $this->assertSame('kernel.exception', $arguments['event'] ?? $arguments[0] ?? null);
        $this->assertArrayNotHasKey('priority', $arguments, 'Story 4.1 (FR42, FR43) owns the priority constant — Story 1.4 must not declare one.');
    }

    public function testSourceFileContainsNoBannedImports(): void
    {
        $sourcePath = \dirname(__DIR__, 6) . '/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php';
        $this->assertFileExists($sourcePath);

        $contents = \file_get_contents($sourcePath);
        $this->assertNotFalse($contents);

        $banned = [
            'use Symfony\Component\HttpKernel\Exception\\',
            'use Doctrine\\',
            'use Symfony\Component\Messenger\\',
            'use Monolog\\',
            'use Symfony\Bridge\Monolog\\',
            'use App\\',
        ];

        foreach ($banned as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $contents,
                \sprintf(
                    'ExceptionResponder.php must not contain "%s" — Story 1.4 AC #14, relaxed for Psr\Log\ in Story 2.4 AC #13 (NFR22).',
                    $needle,
                ),
            );
        }
    }

    /**
     * Story 3.2 (NFR12) — pins the defense-in-depth wiring: `buildLogContext` must invoke
     * `RedactionDenylist::filter` even though the canonical 8-field log shape contains no
     * denylist-named keys (the call is a runtime no-op today; source-text inspection is the
     * right scope per the cycle-guard pattern Story 3.1 established).
     */
    public function testListenerLogContextBuilderInvokesRedactionDenylistFilter(): void
    {
        $reflectionClass = new ReflectionClass(ExceptionResponder::class);
        $sourcePath = $reflectionClass->getFileName();
        $this->assertNotFalse($sourcePath);

        $sourceLines = \file($sourcePath);
        $this->assertNotFalse($sourceLines);

        $reflectionMethod = $reflectionClass->getMethod('buildLogContext');
        $startLine = $reflectionMethod->getStartLine();
        $endLine = $reflectionMethod->getEndLine();
        $this->assertNotFalse($startLine);
        $this->assertNotFalse($endLine);

        $methodSource = \implode('', \array_slice($sourceLines, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString(
            'RedactionDenylist::filter(',
            $methodSource,
            'ExceptionResponder::buildLogContext must invoke RedactionDenylist::filter for NFR12 defense-in-depth (Story 3.2 AC #4).',
        );
    }

    private function makeListener(?LoggerInterface $logger = null): ExceptionResponder
    {
        return new ExceptionResponder(
            new ProblemDetailsFactory('test'),
            new ProblemDetailsResponder(),
            $logger ?? new BufferingLogger(),
        );
    }

    /**
     * @return array{
     *     level: string,
     *     message: string,
     *     context: array{
     *         instance: string,
     *         correlation_id: string,
     *         type: string,
     *         status: int,
     *         exception_class: string,
     *         exception_message: string,
     *         request_uri: string,
     *         request_method: string,
     *     },
     * }
     */
    private function singleLogRecord(BufferingLogger $buffer): array
    {
        $logs = \array_values($buffer->cleanLogs());
        $this->assertCount(1, $logs, 'Listener must emit exactly one log record per invocation.');

        /** @var array{0: string, 1: string, 2: array<string,mixed>} $first */
        $first = $logs[0];
        [$level, $message, $context] = $first;

        // Runtime pin: every key in the FR32 contract is present with the right type.
        // PHPStan trusts the narrow @return shape for caller convenience; this loop ensures
        // a listener regression that drifted from the contract would surface as a test failure
        // (not just a PHPStan false-negative under treatPhpDocTypesAsCertain).
        foreach (['instance', 'correlation_id', 'type', 'exception_class', 'exception_message', 'request_uri', 'request_method'] as $stringKey) {
            $this->assertArrayHasKey($stringKey, $context);
            $this->assertIsString($context[$stringKey], \sprintf('Context["%s"] must be a string per FR32.', $stringKey));
        }

        $this->assertArrayHasKey('status', $context);
        $this->assertIsInt($context['status'], 'Context["status"] must be an int per FR32.');

        return [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
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

    private function makeEvent(string $path, Throwable $exception): ExceptionEvent
    {
        $request = Request::create($path);
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
            {
                throw new LogicException('Test kernel: handle() must not be called by the listener.');
            }
        };

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }
}
