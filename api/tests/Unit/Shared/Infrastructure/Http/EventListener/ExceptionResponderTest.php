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
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Stringable;
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
        $exception = new class ('', 'Bank not found', ['bankId' => '01JABC']) extends DomainException implements NotFound {
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
        $this->assertBodyEquals('01JABC', $body, 'bankId');
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
        // The `_correlation_id` request attribute is contractually populated on every
        // main /api/* request. This test pins the defense-in-depth fallback in the
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
            ['instance', 'correlation_id', 'type', 'status', 'exception_class', 'exception_category', 'exception_message', 'request_uri', 'request_method'],
            \array_keys($looseContext),
            'Context keys must appear in declaration order.',
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
        $this->assertSame('domain_error', $logRecord['context']['exception_category']);
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
        $this->assertSame('runtime_error', $logRecord['context']['exception_category']);
        $this->assertSame('boom', $logRecord['context']['exception_message']);
    }

    /**
     * Pins the SRE contract: LogicException carries the `programmer_error` taxonomy
     * tag and is logged at CRITICAL irrespective of the factory's marker mapping.
     * On-call routes off `exception_category` to separate platform-broken signals
     * (page) from runtime / domain errors (triage).
     */
    public function testLogRecordIsEmittedWithLevelCriticalAndProgrammerErrorCategoryForLogicException(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exceptionEvent = $this->makeEvent('/api/v1/anything', new LogicException('platform misconfigured'));

        $exceptionResponder($exceptionEvent);

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::CRITICAL, $logRecord['level']);
        $this->assertSame(500, $logRecord['context']['status']);
        $this->assertSame('unhandled-exception', $logRecord['context']['type']);
        $this->assertSame(LogicException::class, $logRecord['context']['exception_class']);
        $this->assertSame('programmer_error', $logRecord['context']['exception_category']);
        $this->assertSame('platform misconfigured', $logRecord['context']['exception_message']);
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
        $this->assertSame(400, $logRecord['context']['status']);
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
            'Listener must NOT log when an earlier listener already set the response.',
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
            'Listener must NOT log for paths outside /api/.',
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
            'Log correlation_id must equal body correlation-id (operator pivot parity).',
        );
        $this->assertSame(
            $body['instance'] ?? null,
            $logRecord['context']['instance'],
            'Log instance must equal body instance (operator pivot parity).',
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

        // Instantiate the attribute so the `self::PRIORITY` reference resolves to its int value
        // (raw `getArguments()` returns the un-evaluated AST node otherwise).
        $asEventListener = $attributes[0]->newInstance();
        $this->assertSame('kernel.exception', $asEventListener->event);
        $this->assertSame(
            ExceptionResponder::PRIORITY,
            $asEventListener->priority,
            'The `#[AsEventListener]` attribute must read its priority '
            . 'from `self::PRIORITY` so the constant is the single source of truth. '
            . 'See ExceptionResponderListenerPriorityTest for the kernel-bootstrapped pin.',
        );
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
                    'ExceptionResponder.php must not contain "%s".',
                    $needle,
                ),
            );
        }
    }

    /**
     * Pins the defense-in-depth wiring: `buildLogContext` must invoke
     * `RedactionDenylist::filter` even though the canonical 8-field log shape contains no
     * denylist-named keys (the call is a runtime no-op today; source-text inspection is the
     * right scope per the cycle-guard pattern).
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
            'ExceptionResponder::buildLogContext must invoke RedactionDenylist::filter for defense-in-depth.',
        );
    }

    /**
     * Last-resort static body when the factory throws.
     *
     * We force the throw via the factory's PSR-3 sink: a `DomainException` with a
     * non-whitelisted top-level context value triggers `applyUnserializableSentinel()`
     * which calls `$logger->log(LogLevel::NOTICE, ...)`. Injecting a throwing logger
     * into the factory propagates a `RuntimeException` out of `fromThrowable()` — the
     * exact "factory raises mid-flight" condition this test pins.
     */
    public function testLastResortStaticBodyEmittedWhenFactoryThrows(): void
    {
        $exceptionResponder = $this->makeListener(
            logger: new NullLogger(),
            factoryLogger: $this->throwingLogger('factory boom'),
        );
        $exception = new class ('', 'x', ['proxy' => static fn (): int => 1]) extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode(), (string) $response->getContent());

        // Byte-for-byte equality — the body must be the literal constant, NOT
        // re-encoded JSON, so we are immune to key-order or whitespace drift.
        $this->assertSame(
            '{"type":"internal-error","title":"Internal server error","status":500}',
            $response->getContent(),
        );

        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function testLastResortLogIsCriticalWithSelfFailureClassAndMessage(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener(
            logger: $bufferingLogger,
            factoryLogger: $this->throwingLogger('factory boom'),
        );
        $exception = new class ('', 'x', ['proxy' => static fn (): int => 1]) extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);

        $exceptionResponder($exceptionEvent);

        $logs = \array_values($bufferingLogger->cleanLogs());
        $this->assertCount(1, $logs, 'Self-failure path must emit exactly one CRITICAL log line.');

        /** @var array{0: string, 1: string, 2: array<string, mixed>} $first */
        $first = $logs[0];
        [$level, $message, $context] = $first;

        $this->assertSame(LogLevel::CRITICAL, $level);
        $this->assertStringContainsString('self-failure', $message);
        $this->assertArrayHasKey('self_failure_class', $context);
        $this->assertArrayHasKey('self_failure_message', $context);
        $this->assertSame(RuntimeException::class, $context['self_failure_class']);
        $this->assertSame('factory boom', $context['self_failure_message']);
    }

    public function testLastResortPathSurvivesBrokenLogger(): void
    {
        // Both logger sinks throw: the factory's (forces fromThrowable to throw) AND the
        // listener's (would normally log the self-failure). The fallback response MUST still
        // appear (broken logger never blocks the response).
        $exceptionResponder = $this->makeListener(
            logger: $this->throwingLogger('listener logger boom'),
            factoryLogger: $this->throwingLogger('factory boom'),
        );
        $exception = new class ('', 'x', ['proxy' => static fn (): int => 1]) extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(
            '{"type":"internal-error","title":"Internal server error","status":500}',
            $response->getContent(),
        );
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    /**
     * Sanity test: a normal `DomainException` thrown event still produces the canonical
     * `WARNING` log with `API error response built` and NOT the last-resort body. Pins that
     * the last-resort wrap did not regress the happy path.
     */
    public function testHappyPathStillCallsPrimaryLoggerNotLastResort(): void
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = $this->makeListener($bufferingLogger);
        $exception = new class ('', 'Bank not found') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);

        $exceptionResponder($exceptionEvent);

        $logRecord = $this->singleLogRecord($bufferingLogger);
        $this->assertSame(LogLevel::WARNING, $logRecord['level']);
        $this->assertSame('API error response built', $logRecord['message']);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), (string) $response->getContent());
        $body = (string) $response->getContent();
        $this->assertNotSame(
            '{"type":"internal-error","title":"Internal server error","status":500}',
            $body,
            'Happy path must not emit the last-resort body.',
        );
        $this->assertStringContainsString('"type":"not-found"', $body);
    }

    /**
     * The fallback path must complete in ≤ 1ms. The target is documented, not CI-gated;
     * we run a generous-threshold synthetic loop here so the contract is exercised but
     * remains stable on shared CI hardware. The mean must beat 5ms; the per-iteration
     * budget is a softer 1ms target.
     */
    public function testLastResortPathCompletesUnderOneMillisecondBenchmark(): void
    {
        $exceptionResponder = $this->makeListener(
            logger: new NullLogger(),
            factoryLogger: $this->throwingLogger('factory boom'),
        );
        $exception = new class ('', 'x', ['proxy' => static fn (): int => 1]) extends DomainException implements NotFound {
        };

        $iterations = 1000;
        $start = \hrtime(true);

        for ($i = 0; $i < $iterations; ++$i) {
            $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
            $exceptionResponder($exceptionEvent);
        }

        $elapsedNs = \hrtime(true) - $start;
        $meanMs = ($elapsedNs / $iterations) / 1_000_000;

        // Generous CI-stable threshold (5ms). The 1ms target is the documented goal
        // under typical hardware; this assertion is a regression guard, not a strict
        // perf gate.
        $this->assertLessThan(
            5.0,
            $meanMs,
            \sprintf('Fallback path mean wall-clock %.4fms exceeded 5ms regression guard.', $meanMs),
        );
    }

    /**
     * Build a PSR-3 logger whose every method throws a `RuntimeException`. Used by the
     * self-failure tests to make either the factory's PSR-3 sink (forces
     * `fromThrowable` to throw) or the listener's PSR-3 sink (logger-resilience)
     * fail loudly. Anonymous class — kept inline so the production listener never sees
     * a leaked test-double symbol.
     */
    private function throwingLogger(string $message): LoggerInterface
    {
        return new readonly class ($message) implements LoggerInterface {
            public function __construct(private string $message)
            {
            }

            #[Override]
            public function emergency(string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException($this->message);
            }

            #[Override]
            public function alert(string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException($this->message);
            }

            #[Override]
            public function critical(string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException($this->message);
            }

            #[Override]
            public function error(string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException($this->message);
            }

            #[Override]
            public function warning(string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException($this->message);
            }

            #[Override]
            public function notice(string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException($this->message);
            }

            #[Override]
            public function info(string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException($this->message);
            }

            #[Override]
            public function debug(string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException($this->message);
            }

            #[Override]
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException($this->message);
            }
        };
    }

    private function makeListener(
        ?LoggerInterface $logger = null,
        ?LoggerInterface $factoryLogger = null,
    ): ExceptionResponder {
        // The listener owns the BufferingLogger under test; the factory's PSR-3 sink is wired
        // separately to a NullLogger so any sentinel emissions from `applyUnserializableSentinel`
        // never pollute the listener's `singleLogRecord` assertions (factory and listener emit on
        // independent channels at runtime).
        //
        // `$factoryLogger` lets a self-failure test inject a logger whose
        // `log()` throws into the factory; the factory's `applyUnserializableSentinel()` will
        // re-raise that throw out of `fromThrowable()`, exercising the listener's outer
        // try/catch on a real code path (no PHPUnit mock needed — `ProblemDetailsFactory` is
        // `final readonly` and excluded from MockObject's doubler).
        return new ExceptionResponder(
            new ProblemDetailsFactory('test', $factoryLogger ?? new NullLogger()),
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
     *         exception_category: string,
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

        // Runtime pin: every key in the contract is present with the right type.
        // PHPStan trusts the narrow @return shape for caller convenience; this loop ensures
        // a listener regression that drifted from the contract would surface as a test failure
        // (not just a PHPStan false-negative under treatPhpDocTypesAsCertain).
        foreach (['instance', 'correlation_id', 'type', 'exception_class', 'exception_category', 'exception_message', 'request_uri', 'request_method'] as $stringKey) {
            $this->assertArrayHasKey($stringKey, $context);
            $this->assertIsString($context[$stringKey], \sprintf('Context["%s"] must be a string per the log contract.', $stringKey));
        }

        $this->assertArrayHasKey('status', $context);
        $this->assertIsInt($context['status'], 'Context["status"] must be an int per the log contract.');

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
            #[Override]
            public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
            {
                throw new LogicException('Test kernel: handle() must not be called by the listener.');
            }
        };

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }
}
