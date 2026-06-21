<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\ErrorContract\Application;

use Erpify\Shared\ErrorContract\Application\ProblemBodyTooLargeException;
use Erpify\Shared\ErrorContract\Application\ProblemDetails;
use Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory;
use Erpify\Shared\ErrorContract\Application\RedactionDenylist;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidInput;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidSearchCriteria;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimited;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;
use Erpify\Shared\Search\Domain\Exception\UnknownSearchField;
use Erpify\Shared\Search\Domain\Exception\UnsupportedSearchOperator;
use Erpify\Shared\Search\Domain\FilterOperator;
use JsonSchema\Validator as JsonSchemaValidator;
use JsonSerializable;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use stdClass;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.ExcessivePublicCount")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ProblemDetailsFactory::class)]
final class ProblemDetailsFactoryTest extends TestCase
{
    private const string CID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string INSTANCE = 'urn:uuid:0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    /**
     * env-aware factory helper. Defaults to 'prod' so existing tests' byte-for-byte
     * `extensions` assertions stay green (prod omits the `debug` extension entirely). New
     * tests pass `'dev'` / `'test'` / `'staging'` / `'prod'` explicitly.
     *
     * accepts an optional `LoggerInterface` so tests that assert on the per-replacement
     * sentinel log record can pass a `BufferingLogger`. Defaults to `NullLogger` so existing tests'
     * behaviour is unchanged byte-for-byte.
     */
    private function factoryFor(string $environment = 'prod', ?LoggerInterface $logger = null): ProblemDetailsFactory
    {
        return new ProblemDetailsFactory($environment, $logger ?? new NullLogger());
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[DataProvider('provideMarkers')]
    public function testStatusMappingForEachMarker(
        DomainException $exception,
        int $expectedStatus,
        string $expectedDefaultType,
    ): void {
        $problemDetailsFactory = $this->factoryFor();

        $problemDetails = $problemDetailsFactory->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame($expectedStatus, $problemDetails->status);
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[DataProvider('provideMarkers')]
    public function testDefaultTypeForEachMarker(
        DomainException $exception,
        int $expectedStatus,
        string $expectedDefaultType,
    ): void {
        $problemDetailsFactory = $this->factoryFor();

        $problemDetails = $problemDetailsFactory->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame($expectedDefaultType, $problemDetails->type);
    }

    /**
     * @return iterable<string, array{DomainException, int, string}>
     */
    public static function provideMarkers(): iterable
    {
        yield 'NotFound' => [
            new class ('', 'x') extends DomainException implements NotFound {
            },
            404,
            'not-found',
        ];

        yield 'Conflict' => [
            new class ('', 'x') extends DomainException implements Conflict {
            },
            409,
            'conflict',
        ];

        yield 'Forbidden' => [
            new class ('', 'x') extends DomainException implements Forbidden {
            },
            403,
            'forbidden',
        ];

        yield 'Unauthenticated' => [
            new class ('', 'x') extends DomainException implements Unauthenticated {
            },
            401,
            'unauthenticated',
        ];

        yield 'InvariantViolation' => [
            new class ('', 'x') extends DomainException implements InvariantViolation {
            },
            422,
            'invariant-violation',
        ];

        yield 'InvalidInput' => [
            new class ('', 'x') extends DomainException implements InvalidInput {
            },
            400,
            'invalid-input',
        ];

        yield 'RateLimited' => [
            new class ('', 'x') extends DomainException implements RateLimited {
            },
            429,
            'rate-limited',
        ];

        yield 'InvalidSearchCriteria' => [
            new class ('', 'x') extends DomainException implements InvalidSearchCriteria {
            },
            422,
            'invalid-search-criteria',
        ];
    }

    public function testTypeOverrideWinsWhenNonEmpty(): void
    {
        $exception = new class ('does-not-matter', 'Bank not found') extends DomainException implements NotFound {
            #[Override]
            public function type(): string
            {
                return 'bank-not-found';
            }
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame('bank-not-found', $problemDetails->type);
        $this->assertSame(404, $problemDetails->status);
    }

    public function testMultiMarkerFirstDeclaredWinsBothOrders(): void
    {
        $problemDetailsFactory = $this->factoryFor();

        $notFoundFirst = new class ('', 'x') extends DomainException implements NotFound, Conflict {
        };
        $conflictFirst = new class ('', 'x') extends DomainException implements Conflict, NotFound {
        };

        $problemDetails = $problemDetailsFactory->fromThrowable($notFoundFirst, self::CID, self::INSTANCE);
        $resolvedConflictFirst = $problemDetailsFactory->fromThrowable($conflictFirst, self::CID, self::INSTANCE);

        $this->assertSame(404, $problemDetails->status);
        $this->assertSame('not-found', $problemDetails->type);

        $this->assertSame(409, $resolvedConflictFirst->status);
        $this->assertSame('conflict', $resolvedConflictFirst->type);
    }

    public function testPlainDomainExceptionMapsToFiveHundredDomainError(): void
    {
        $exception = new class ('', 'plain') extends DomainException {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame(500, $problemDetails->status);
        $this->assertSame('domain-error', $problemDetails->type);
    }

    public function testNonDomainThrowableMapsToFiveHundredUnhandledException(): void
    {
        $runtimeException = new RuntimeException('something broke');

        // `'test'` env preserves message-pass-through; prod swaps the title to the
        // safe literal (covered by `testProdEnvironmentUnhandledExceptionTitleIsSafeLiteral`).
        $problemDetails = $this->factoryFor('test')->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $this->assertSame(500, $problemDetails->status);
        $this->assertSame('unhandled-exception', $problemDetails->type);
        $this->assertSame('something broke', $problemDetails->title);
        $this->assertNull($problemDetails->detail);
        // in the `'test'` env the body carries a `debug` extension; prod omits it.
        $this->assertArrayHasKey('debug', $problemDetails->extensions);
    }

    public function testNonDomainThrowableWithEmptyMessageFallsBackToSafeLiteral(): void
    {
        $runtimeException = new RuntimeException('');

        // in `'test'` env, the title fallback for empty message is the same safe
        // literal that prod uses unconditionally on this branch.
        $problemDetails = $this->factoryFor('test')->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $this->assertSame('An unexpected error occurred.', $problemDetails->title);
    }

    public function testCorrelationIdAndInstancePassThroughVerbatim(): void
    {
        $problemDetailsFactory = $this->factoryFor();
        $exception = new class ('', 'x') extends DomainException implements NotFound {
        };

        $problemDetails = $problemDetailsFactory->fromThrowable($exception, '', '');
        $this->assertSame('', $problemDetails->correlationId);
        $this->assertSame('', $problemDetails->instance);

        $longCid = \str_repeat('a', 4096);
        $longInstance = '/api/' . \str_repeat('z', 4096);
        $longResult = $problemDetailsFactory->fromThrowable($exception, $longCid, $longInstance);
        $this->assertSame($longCid, $longResult->correlationId);
        $this->assertSame($longInstance, $longResult->instance);
    }

    public function testTitlePassThroughFromDomainException(): void
    {
        $exception = new class ('', 'Bank not found') extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame('Bank not found', $problemDetails->title);
        $this->assertNull($problemDetails->detail);
    }

    public function testContextScalarArrayJsonSerializableValuesPassThrough(): void
    {
        $serializable = new class implements JsonSerializable {
            /**
             * @return array{nested: bool}
             */
            #[Override]
            public function jsonSerialize(): array
            {
                return ['nested' => true];
            }
        };

        $context = [
            'int' => 42,
            'float' => 3.14,
            'string' => 'hello',
            'bool' => true,
            'null' => null,
            'array' => ['a', 'b', 'c'],
            'serializable' => $serializable,
        ];

        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame($context, $problemDetails->extensions);
    }

    /**
     * non-whitelisted top-level context values are SUBSTITUTED with the literal
     * `'[unserializable]'` sentinel instead of being silently dropped (the previous
     * behavior). Body-side: every original key survives with the sentinel value.
     */
    public function testContextNonWhitelistedValuesAreSubstitutedWithSentinel(): void
    {
        $resource = \fopen('php://memory', 'r');
        $this->assertNotFalse($resource);

        try {
            $closure = static fn (): int => 1;
            $object = new stdClass();
            $object->field = 'value';

            $context = [
                'closure' => $closure,
                'resource' => $resource,
                'object' => $object,
                'safe' => 1,
            ];

            $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
            };

            $result = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

            $this->assertSame(
                [
                    'closure' => '[unserializable]',
                    'resource' => '[unserializable]',
                    'object' => '[unserializable]',
                    'safe' => 1,
                ],
                $result->extensions,
                'Non-whitelisted values must be replaced (not dropped) with the literal sentinel; '
                . 'whitelisted values pass through unchanged.',
            );
        } finally {
            \fclose($resource);
        }
    }

    /**
     * every substitution emits exactly one PSR-3 `notice` log record with the
     * four-field context shape `{instance, correlation_id, context_key, original_type}`.
     * `original_type` is the {@see ProblemDetailsFactory::sanitiseExceptionClass}-cleaned
     * FQCN for objects (no NUL byte, no `__FILE__`-shaped path leak from anonymous-class FQCNs).
     */
    public function testFactoryEmitsOnePsr3NoticePerSentinelSubstitutionWithFourFieldContext(): void
    {
        $bufferingLogger = new BufferingLogger();
        $closure = static fn (): int => 1;
        $stdObject = new stdClass();

        $context = [
            'cb' => $closure,
            'o' => $stdObject,
            'safe' => 1,
        ];

        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $this->factoryFor('prod', $bufferingLogger)->fromThrowable($exception, self::CID, self::INSTANCE);

        $logs = $bufferingLogger->cleanLogs();
        $this->assertCount(
            2,
            $logs,
            'Exactly one notice per non-whitelisted top-level value (closure + stdClass = 2 records).',
        );

        $byKey = [];

        foreach ($logs as $log) {
            $logContext = $this->assertSentinelLogShape($log);
            $byKey[$logContext['context_key']] = $logContext['original_type'];
        }

        $this->assertArrayHasKey('cb', $byKey);
        $this->assertArrayHasKey('o', $byKey);
        $this->assertSame('Closure', $byKey['cb']);
        $this->assertSame(stdClass::class, $byKey['o']);
    }

    /**
     * anonymous-class context value (e.g. a Doctrine proxy stand-in carrying
     * `__get` / `__call`) substitutes to the body sentinel, and the log's `original_type`
     * reuses {@see ProblemDetailsFactory::sanitiseExceptionClass}: no `\0`, no path leak from
     * the `\0/path:line$N` anonymous-class suffix PHP appends to the FQCN.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function testFactoryDoctrineProxyStandInLogsSanitisedOriginalTypeWithoutNulOrPathLeak(): void
    {
        $bufferingLogger = new BufferingLogger();
        $proxy = new class {
            public function __get(string $name): null
            {
                return null;
            }

            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): null
            {
                return null;
            }
        };

        $exception = new class ('', 'x', ['proxy' => $proxy]) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor('prod', $bufferingLogger)
            ->fromThrowable($exception, self::CID, self::INSTANCE)
        ;

        $this->assertArrayHasKey('proxy', $problemDetails->extensions);
        $this->assertSame('[unserializable]', $problemDetails->extensions['proxy']);

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(
            "\0",
            $encoded,
            'Wire body must not carry the anonymous-class NUL-byte FQCN.',
        );
        $this->assertStringNotContainsString(__FILE__, $encoded, 'Wire body must not leak the throw-site file path.');

        $logs = \array_values($bufferingLogger->cleanLogs());
        $this->assertCount(1, $logs);
        $logContext = $this->assertSentinelLogShape($logs[0]);
        $this->assertSame('proxy', $logContext['context_key']);
        $originalType = $logContext['original_type'];
        $this->assertStringNotContainsString(
            "\0",
            $originalType,
            'Sanitised original_type must not contain a NUL byte.',
        );
        $this->assertStringNotContainsString(
            __FILE__,
            $originalType,
            'Sanitised original_type must not leak the file path.',
        );
        $this->assertStringContainsString(
            '@anonymous',
            $originalType,
            'Anonymous-class FQCN convention preserved up to the @anonymous marker.',
        );
    }

    /**
     * substitution is single-level. A non-whitelisted value LIVING INSIDE a
     * top-level array survives unchanged: the top-level value is `array` (whitelisted) so the
     * factory does not descend, and no sentinel log is emitted. Documents the deliberate
     * scope limit.
     */
    public function testFactorySentinelDoesNotApplyToNestedObjectsInsideWhitelistedArray(): void
    {
        $bufferingLogger = new BufferingLogger();
        $stdObject = new stdClass();
        $stdObject->field = 'nested-value';

        $exception = new class ('', 'x', ['user' => ['o' => $stdObject]]) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor('prod', $bufferingLogger)
            ->fromThrowable($exception, self::CID, self::INSTANCE)
        ;

        $this->assertArrayHasKey('user', $problemDetails->extensions);
        $this->assertSame(['o' => $stdObject], $problemDetails->extensions['user']);
        $this->assertCount(
            0,
            $bufferingLogger->cleanLogs(),
            'Single-level scope: nested values must not trigger sentinel emission.',
        );
    }

    /**
     * denylist still wins (contract preserved). A denylisted key carrying
     * a non-whitelisted value is REMOVED from extensions — the substitution branch never sees
     * it, so no sentinel log is emitted either.
     */
    public function testFactoryDenylistedKeyWithNonWhitelistedValueIsRemovedAndNoSentinelLogIsEmitted(): void
    {
        $bufferingLogger = new BufferingLogger();
        $closure = static fn (): int => 1;

        $context = ['password' => $closure, 'safe' => 'kept'];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor('prod', $bufferingLogger)
            ->fromThrowable($exception, self::CID, self::INSTANCE)
        ;

        $this->assertArrayNotHasKey('password', $problemDetails->extensions);
        $this->assertSame(['safe' => 'kept'], $problemDetails->extensions);
        $this->assertCount(
            0,
            $bufferingLogger->cleanLogs(),
            'Denylist strip happens before the substitution branch — no sentinel log.',
        );
    }

    /**
     * default-deny on unknown exception types. A throwable that is neither
     * a DomainException, nor a ValidationFailedException in the chain, nor a Symfony bridge
     * case, lands on `type='unhandled-exception'` / `status=500` / `extensions=[]`, and in
     * `'prod'` env carries the safe literal title with NO `debug` extension. The factory emits
     * no sentinel log on this path (the listener emits its own `critical` line independently).
     */
    public function testFactoryDefaultDenyTerminalBranchInProdEnvHasSafeLiteralTitleNoDebugAndEmptyExtensions(): void
    {
        $bufferingLogger = new BufferingLogger();
        $runtimeException = new RuntimeException('boom');

        $problemDetails = $this->factoryFor('prod', $bufferingLogger)
            ->fromThrowable($runtimeException, self::CID, self::INSTANCE)
        ;

        $this->assertSame('unhandled-exception', $problemDetails->type);
        $this->assertSame(500, $problemDetails->status);
        $this->assertSame('An unexpected error occurred.', $problemDetails->title);
        $this->assertSame(
            [],
            $problemDetails->extensions,
            'Default-deny: extensions must be empty (no debug, no leaked context).',
        );
        $this->assertArrayNotHasKey('debug', $problemDetails->extensions);
        $this->assertCount(
            0,
            $bufferingLogger->cleanLogs(),
            'Default-deny path must not emit a factory-side sentinel log.',
        );
    }

    public function testReservedKeysAreFilteredFromExtensions(): void
    {
        $context = [
            'type' => 'x',
            'title' => 'y',
            'status' => 'z',
            'detail' => 'w',
            'instance' => 'v',
            'correlation-id' => 'u',
            'violations' => 'must-not-leak',
            'safe_key' => 'ok',
        ];

        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame(['safe_key' => 'ok'], $problemDetails->extensions);
    }

    public function testNonDomainThrowableYieldsEmptyExtensions(): void
    {
        $runtimeException = new RuntimeException('boom');

        $problemDetails = $this->factoryFor()->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $this->assertSame([], $problemDetails->extensions);
    }

    public function testMarkerStatusMapHasExactlyTheCanonicalEightEntries(): void
    {
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);
        $constant = $reflectionClass->getReflectionConstant('MARKER_STATUS_MAP');

        $this->assertNotFalse($constant, 'MARKER_STATUS_MAP constant must exist on ProblemDetailsFactory.');

        $value = $constant->getValue();
        $this->assertIsArray($value);

        $expected = [
            NotFound::class => 404,
            Conflict::class => 409,
            Forbidden::class => 403,
            Unauthenticated::class => 401,
            InvariantViolation::class => 422,
            InvalidInput::class => 400,
            RateLimited::class => 429,
            InvalidSearchCriteria::class => 422,
        ];

        $this->assertSame(
            $expected,
            $value,
            'MARKER_STATUS_MAP must contain exactly the eight canonical marker→status entries in canonical order.',
        );
    }

    public function testMarkerDefaultTypeMapHasExactlyTheCanonicalEightEntries(): void
    {
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);
        $constant = $reflectionClass->getReflectionConstant('MARKER_DEFAULT_TYPE_MAP');

        $this->assertNotFalse($constant, 'MARKER_DEFAULT_TYPE_MAP constant must exist on ProblemDetailsFactory.');

        $value = $constant->getValue();
        $this->assertIsArray($value);

        $expected = [
            NotFound::class => 'not-found',
            Conflict::class => 'conflict',
            Forbidden::class => 'forbidden',
            Unauthenticated::class => 'unauthenticated',
            InvariantViolation::class => 'invariant-violation',
            InvalidInput::class => 'invalid-input',
            RateLimited::class => 'rate-limited',
            InvalidSearchCriteria::class => 'invalid-search-criteria',
        ];

        $this->assertSame(
            $expected,
            $value,
            'MARKER_DEFAULT_TYPE_MAP must contain exactly the eight canonical '
            . 'marker→default-type entries in canonical order.',
        );
    }

    /**
     * It narrows the original `'Symfony\\'` ban to an explicit allowlist of Symfony imports
     * (`HttpKernel\Exception\HttpExceptionInterface`, `Security\Core\Exception\AccessDeniedException`,
     * `Security\Core\Exception\AuthenticationException`, plus `HttpFoundation\Response` for the named
     * `Response::HTTP_*` status constants used in `MARKER_STATUS_MAP` / `HTTP_STATUS_TYPE_MAP`). Every
     * other Symfony namespace is still banned via the narrower prefix list below.
     */
    public function testSourceFileContainsNoBannedImports(): void
    {
        $sourcePath = \dirname(__DIR__, 5) . '/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php';
        $this->assertFileExists($sourcePath);

        $contents = \file_get_contents($sourcePath);
        $this->assertNotFalse($contents);

        $banned = [
            'Doctrine\\',
            'Psr\Http\\',
            'Symfony\Component\Messenger\\',
            'Symfony\Component\Routing\\',
            'Symfony\Bundle\\',
            'Symfony\Bridge\\',
            'App\\',
        ];

        foreach ($banned as $prefix) {
            $this->assertStringNotContainsString(
                'use ' . $prefix,
                $contents,
                \sprintf(
                    'ProblemDetailsFactory.php must not import any %s symbol — factory stays mapping-focused.',
                    $prefix,
                ),
            );
        }
    }

    public function testFactoryHasEnvironmentConstructorAndIsFinal(): void
    {
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);

        $this->assertTrue($reflectionClass->isFinal(), 'ProblemDetailsFactory must be final.');

        // `string $environment` autowired from `%kernel.environment%`.
        // `LoggerInterface $logger` (PSR-3) for the per-replacement sentinel notice.
        $constructor = $reflectionClass->getConstructor();
        $this->assertInstanceOf(
            ReflectionMethod::class,
            $constructor,
            'ProblemDetailsFactory must declare a constructor for the environment injection.',
        );
        $this->assertSame(2, $constructor->getNumberOfParameters());
        $this->assertSame(2, $constructor->getNumberOfRequiredParameters());

        $parameters = $constructor->getParameters();
        $this->assertArrayHasKey(0, $parameters);
        $environmentParameter = $parameters[0];
        $this->assertSame('environment', $environmentParameter->getName());
        $environmentType = $environmentParameter->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $environmentType);
        $this->assertSame('string', $environmentType->getName());
        $this->assertFalse($environmentType->allowsNull());
        $this->assertTrue(
            $environmentParameter->isPromoted(),
            'Constructor argument must be a promoted readonly property to keep the factory stateless '
            . 'beyond the env value.',
        );

        $this->assertArrayHasKey(1, $parameters);
        $loggerParameter = $parameters[1];
        $this->assertSame('logger', $loggerParameter->getName());
        $loggerType = $loggerParameter->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $loggerType);
        $this->assertSame(LoggerInterface::class, $loggerType->getName());
        $this->assertFalse($loggerType->allowsNull());
        $this->assertTrue(
            $loggerParameter->isPromoted(),
            'Logger argument must be a promoted readonly property — keeps the factory stateless.',
        );

        // Seam methods exist; it has now filled `applyUnserializableSentinel`.
        // Visibility is private because the class is final (Rector privatizes protected methods on
        // final classes — see memory `feedback_api_lint_privatize_final.md`).
        $this->assertTrue($reflectionClass->hasMethod('redactKeys'));
        $this->assertTrue($reflectionClass->getMethod('redactKeys')->isPrivate());
        $this->assertTrue($reflectionClass->hasMethod('applyUnserializableSentinel'));
        $this->assertTrue($reflectionClass->getMethod('applyUnserializableSentinel')->isPrivate());
    }

    public function testJsonSerializableExtensionsAreEncodableEndToEnd(): void
    {
        $serializable = new class implements JsonSerializable {
            /**
             * @return array{ok: bool}
             */
            #[Override]
            public function jsonSerialize(): array
            {
                return ['ok' => true];
            }
        };

        $context = ['payload' => $serializable, 'count' => 7];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $decoded = \json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('payload', $decoded);
        $this->assertArrayHasKey('count', $decoded);
        $this->assertSame(['ok' => true], $decoded['payload']);
        $this->assertSame(7, $decoded['count']);
    }

    #[DataProvider('provideHttpExceptionInterfaceMapsStatusToCanonicalTypeCases')]
    public function testHttpExceptionInterfaceMapsStatusToCanonicalType(int $status, string $expectedType): void
    {
        $httpException = new HttpException($status, 'message');

        $problemDetails = $this->factoryFor()->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame($status, $problemDetails->status);
        $this->assertSame($expectedType, $problemDetails->type);
    }

    /**
     * HttpExceptionInterface branch: status from getStatusCode(), type from HTTP_STATUS_TYPE_MAP.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function provideHttpExceptionInterfaceMapsStatusToCanonicalTypeCases(): iterable
    {
        yield '400' => [400, 'invalid-input'];
        yield '401' => [401, 'unauthenticated'];
        yield '403' => [403, 'forbidden'];
        yield '404' => [404, 'not-found'];
        yield '409' => [409, 'conflict'];
        yield '422' => [422, 'invariant-violation'];
        yield '429' => [429, 'rate-limited'];
    }

    public function testHttpExceptionWithUnmappedStatusFallsBackToHttpError(): void
    {
        $httpException = new HttpException(410, 'gone');

        $problemDetails = $this->factoryFor()->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame(410, $problemDetails->status);
        $this->assertSame('http-error', $problemDetails->type);
        $this->assertSame('gone', $problemDetails->title);
    }

    public function testHttpExceptionWithEmptyMessageFallsBackToSafeLiteral(): void
    {
        $httpException = new HttpException(503, '');

        $problemDetails = $this->factoryFor()->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame('An HTTP error occurred.', $problemDetails->title);
    }

    public function testHttpExceptionTitleComesFromGetMessageVerbatim(): void
    {
        $httpException = new HttpException(404, 'Resource not found by id 42');

        $problemDetails = $this->factoryFor()->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame('Resource not found by id 42', $problemDetails->title);
    }

    public function testHttpExceptionDetailIsNullAndExtensionsEmpty(): void
    {
        $httpException = new HttpException(404, 'gone');

        $problemDetails = $this->factoryFor()->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertNull($problemDetails->detail);
        $this->assertSame([], $problemDetails->extensions);
    }

    public function testAccessDeniedExceptionMapsToForbidden(): void
    {
        $accessDeniedException = new AccessDeniedException();

        $problemDetails = $this->factoryFor()->fromThrowable($accessDeniedException, self::CID, self::INSTANCE);

        $this->assertSame(403, $problemDetails->status);
        $this->assertSame('forbidden', $problemDetails->type);
        $this->assertSame('Access Denied.', $problemDetails->title);
    }

    public function testAccessDeniedExceptionTitleFallsBackOnEmptyMessage(): void
    {
        $accessDeniedException = new AccessDeniedException('');

        $problemDetails = $this->factoryFor()->fromThrowable($accessDeniedException, self::CID, self::INSTANCE);

        $this->assertSame('Access denied.', $problemDetails->title);
    }

    public function testAccessDeniedExceptionWithCustomMessage(): void
    {
        $accessDeniedException = new AccessDeniedException('Bank not authorized.');

        $problemDetails = $this->factoryFor()->fromThrowable($accessDeniedException, self::CID, self::INSTANCE);

        $this->assertSame('Bank not authorized.', $problemDetails->title);
        $this->assertSame(403, $problemDetails->status);
        $this->assertSame('forbidden', $problemDetails->type);
    }

    public function testAuthenticationExceptionMapsToUnauthenticated(): void
    {
        $authenticationException = new class ('Token expired.') extends AuthenticationException {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($authenticationException, self::CID, self::INSTANCE);

        $this->assertSame(401, $problemDetails->status);
        $this->assertSame('unauthenticated', $problemDetails->type);
        $this->assertSame('Token expired.', $problemDetails->title);
    }

    public function testAuthenticationExceptionTitleFallsBackOnEmptyMessage(): void
    {
        $authenticationException = new class ('') extends AuthenticationException {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($authenticationException, self::CID, self::INSTANCE);

        $this->assertSame('Authentication required.', $problemDetails->title);
    }

    public function testDomainExceptionTakesPrecedenceOverSymfonyBranches(): void
    {
        // Artificial multi-implementer: DomainException + HttpExceptionInterface (the same Symfony interface
        // the factory's branch 4 keys on). The DomainException branch must win — pins the branch order
        // in fromThrowable() (places Symfony branches AFTER the DomainException branch).
        $title = 'Bank not found';
        $exception = new class ('', $title) extends DomainException implements NotFound, HttpExceptionInterface {
            #[Override]
            public function getStatusCode(): int
            {
                // Returns a status that disagrees with NotFound's 404 — if precedence regressed and the
                // HttpExceptionInterface branch fired instead, the assertion below would catch it.
                return 418;
            }

            /**
             * @return array<string, string>
             */
            #[Override]
            public function getHeaders(): array
            {
                return [];
            }
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        // Status 404 (from marker — NOT 418 from getStatusCode), type 'not-found'
        // (from MARKER_DEFAULT_TYPE_MAP — NOT 'http-error').
        $this->assertSame(404, $problemDetails->status);
        $this->assertSame('not-found', $problemDetails->type);
        $this->assertSame('Bank not found', $problemDetails->title);
    }

    public function testHttpStatusTypeMapHasExactlyTheCanonicalSevenEntries(): void
    {
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);
        $constant = $reflectionClass->getReflectionConstant('HTTP_STATUS_TYPE_MAP');

        $this->assertNotFalse($constant, 'HTTP_STATUS_TYPE_MAP constant must exist on ProblemDetailsFactory.');

        $value = $constant->getValue();
        $this->assertIsArray($value);

        $expected = [
            400 => 'invalid-input',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not-found',
            409 => 'conflict',
            422 => 'invariant-violation',
            429 => 'rate-limited',
        ];

        $this->assertSame(
            $expected,
            $value,
            'HTTP_STATUS_TYPE_MAP must contain exactly the seven canonical status→type entries in canonical order.',
        );
    }

    public function testHttpStatusTypeMapValuesMirrorMarkerDefaultTypeMapValues(): void
    {
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);

        $markerStatusConstant = $reflectionClass->getReflectionConstant('MARKER_STATUS_MAP');
        $markerTypeConstant = $reflectionClass->getReflectionConstant('MARKER_DEFAULT_TYPE_MAP');
        $httpTypeConstant = $reflectionClass->getReflectionConstant('HTTP_STATUS_TYPE_MAP');

        $this->assertNotFalse($markerStatusConstant);
        $this->assertNotFalse($markerTypeConstant);
        $this->assertNotFalse($httpTypeConstant);

        $markerStatus = $markerStatusConstant->getValue();
        $markerType = $markerTypeConstant->getValue();
        $httpType = $httpTypeConstant->getValue();

        $this->assertIsArray($markerStatus);
        $this->assertIsArray($markerType);
        $this->assertIsArray($httpType);

        // Build the inverted view of MARKER_DEFAULT_TYPE_MAP keyed by status, using MARKER_STATUS_MAP to invert.
        $derived = [];

        foreach ($markerStatus as $marker => $status) {
            $this->assertIsString($marker);
            $this->assertIsInt($status);
            $this->assertArrayHasKey($marker, $markerType);

            // First-wins: the bridge map carries the GENERIC marker type per status (e.g.
            // 400 → invalid-input). Specific markers sharing a status (InvalidSearchCriteria)
            // are declared AFTER the generic one in MARKER_STATUS_MAP and must not displace it.
            if (!\array_key_exists($status, $derived)) {
                $derived[$status] = $markerType[$marker];
            }
        }

        \ksort($derived);
        \ksort($httpType);

        $this->assertSame(
            $derived,
            $httpType,
            'HTTP_STATUS_TYPE_MAP must use the same type strings as MARKER_DEFAULT_TYPE_MAP for the same status, '
            . 'so PWA `type`-only routing is uniform across DomainException markers, Security Core, '
            . 'and Symfony HttpException sources.',
        );
    }

    public function testUnknownSearchFieldMapsTo422WithItsExplicitType(): void
    {
        $problemDetails = $this->factoryFor()->fromThrowable(
            UnknownSearchField::named('shoeSize'),
            self::CID,
            self::INSTANCE,
        );

        // The InvalidSearchCriteria marker supplies the 422; the concrete exception's
        // explicit type() wins over the marker default ('invalid-search-criteria').
        $this->assertSame(422, $problemDetails->status);
        $this->assertSame('unknown-search-field', $problemDetails->type);
        $this->assertSame(['field' => 'shoeSize'], $problemDetails->extensions);
    }

    public function testUnsupportedSearchOperatorMapsTo422WithItsExplicitType(): void
    {
        $problemDetails = $this->factoryFor()->fromThrowable(
            UnsupportedSearchOperator::forField('id', FilterOperator::Contains),
            self::CID,
            self::INSTANCE,
        );

        // Mirror of the UnknownSearchField pin — also proves the two-key context
        // (field + wire operator token) survives the whitelist into extensions.
        $this->assertSame(422, $problemDetails->status);
        $this->assertSame('unsupported-search-operator', $problemDetails->type);
        $this->assertSame(['field' => 'id', 'operator' => 'contains'], $problemDetails->extensions);
    }

    public function testRuntimeExceptionStillFallsThroughToUnhandledException(): void
    {
        // CRITICAL: use the GLOBAL \RuntimeException, NOT Symfony\Component\Security\Core\Exception\RuntimeException
        // (which AccessDeniedException extends — easy footgun).
        $runtimeException = new RuntimeException('boom');

        // exercising the message-pass-through semantic via `'test'`. The prod-safe
        // literal is pinned by `testProdEnvironmentUnhandledExceptionTitleIsSafeLiteral`.
        $problemDetails = $this->factoryFor('test')->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $this->assertSame(500, $problemDetails->status);
        $this->assertSame('unhandled-exception', $problemDetails->type);
        $this->assertSame('boom', $problemDetails->title);
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     */
    public function testValidationFailedExceptionMapsTo422ValidationFailedWithViolations(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'This value should not be blank.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'name',
                invalidValue: '',
                code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
            new ConstraintViolation(
                message: 'This value is not a valid email address.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'email',
                invalidValue: 'invalid',
                code: 'bd79c0ab-ddba-46cc-a703-a7a4b08de310',
            ),
            new ConstraintViolation(
                message: 'This value should be greater than or equal to 18.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'age',
                invalidValue: 17,
                code: 'ea4e51d1-3342-48bd-87f1-9e672cd90cad',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(
            value: ['name' => '', 'email' => 'invalid', 'age' => 17],
            violations: $constraintViolationList,
        );

        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertSame(422, $problemDetails->status);
        $this->assertSame('validation-failed', $problemDetails->type);
        $this->assertSame('Validation failed.', $problemDetails->title);
        $this->assertNull($problemDetails->detail);
        $this->assertArrayHasKey('violations', $problemDetails->extensions);

        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertCount(3, $violations);

        $this->assertExpectedViolationEntry(
            $violations,
            0,
            'name',
            'This value should not be blank.',
            'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
        );
        $this->assertExpectedViolationEntry(
            $violations,
            1,
            'email',
            'This value is not a valid email address.',
            'bd79c0ab-ddba-46cc-a703-a7a4b08de310',
        );
        $this->assertExpectedViolationEntry(
            $violations,
            2,
            'age',
            'This value should be greater than or equal to 18.',
            'ea4e51d1-3342-48bd-87f1-9e672cd90cad',
        );
    }

    public function testValidationFailedExceptionViolationKeyOrderIsFieldMessageCode(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'm',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'p',
                invalidValue: null,
                code: 'C',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertMatchesRegularExpression(
            '/"violations":\[\{"field":"p","message":"m","code":"C"}]/',
            $json,
            'Violation entry must serialize with the deterministic key order field, message, code.',
        );
    }

    public function testValidationFailedExceptionViolationCodeFallsBackToEmptyStringOnNull(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'm',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'p',
                invalidValue: null,
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertCount(1, $violations);
        $this->assertArrayHasKey(0, $violations);
        $this->assertIsArray($violations[0]);
        $this->assertArrayHasKey('code', $violations[0]);
        $this->assertSame('', $violations[0]['code']);
        $this->assertNotNull($violations[0]['code']);
    }

    public function testValidationFailedExceptionViolationCodePassesThroughEmptyStringWithoutCoalescing(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'm',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'p',
                invalidValue: null,
                code: '',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertArrayHasKey(0, $violations);
        $this->assertIsArray($violations[0]);
        $this->assertArrayHasKey('code', $violations[0]);
        $this->assertSame('', $violations[0]['code']);
        $this->assertNotNull($violations[0]['code']);
    }

    public function testValidationFailedExceptionEmptyPropertyPathFallsBackToValueLiteral(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'm',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: '',
                invalidValue: null,
                code: 'C',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        // Wire contract: the PWA routes per-field on `violations[].field`; an empty value
        // would force string-comparison fallbacks at the consumer. The factory collapses any
        // still-empty path onto the neutral literal `'value'` as a last-resort safeguard.
        // Callers SHOULD pass `propertyPath:` to `Validator::ensure` for scalar-root
        // validations to surface a meaningful field name (e.g. `'id'`) instead.
        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertCount(1, $violations);
        $this->assertArrayHasKey(0, $violations);
        $this->assertIsArray($violations[0]);
        $this->assertArrayHasKey('field', $violations[0]);
        $this->assertSame('value', $violations[0]['field']);
    }

    public function testValidationFailedExceptionWithEmptyListProducesEmptyViolationsArray(): void
    {
        $constraintViolationList = new ConstraintViolationList([]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertSame(['violations' => []], $problemDetails->extensions);

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"violations":[]', $json);
    }

    public function testValidationFailedExceptionViolationsExtensionSerializesAsJsonArrayNotObject(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('m1', null, [], null, 'a', null, null, 'X'),
            new ConstraintViolation('m2', null, [], null, 'b', null, null, 'Y'),
            new ConstraintViolation('m3', null, [], null, 'c', null, null, 'Z'),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertStringContainsString(
            '"violations":[{',
            $json,
            'violations must serialize as a JSON array, not an object.',
        );
        $this->assertSame(1, \preg_match('/"violations":\[\{/', $json));
        $this->assertDoesNotMatchRegularExpression('/"violations":\{/', $json);
    }

    public function testValidationFailedExceptionTitleIsTheLiteralValidationFailedNotTheMessage(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                'This value should not be blank.',
                null,
                [],
                null,
                'name',
                '',
                null,
                'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
            new ConstraintViolation(
                'This value is not a valid email address.',
                null,
                [],
                null,
                'email',
                'invalid',
                null,
                'bd79c0ab-ddba-46cc-a703-a7a4b08de310',
            ),
            new ConstraintViolation(
                'This value should be greater than or equal to 18.',
                null,
                [],
                null,
                'age',
                17,
                null,
                'ea4e51d1-3342-48bd-87f1-9e672cd90cad',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertSame('Validation failed.', $problemDetails->title);

        // The validator's __toString() (used as the exception message) concatenates root + every
        // path + every message + every code. It must NOT leak into title.
        $this->assertNotSame($problemDetails->title, $validationFailedException->getMessage());
        $this->assertGreaterThan(\strlen($problemDetails->title), \strlen($validationFailedException->getMessage()));
    }

    public function testValidationFailedExceptionDoesNotPropagateInvalidValueOrRoot(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'This value should not be blank.',
                messageTemplate: null,
                parameters: [],
                root: ['password' => 'leaked-secret'],
                propertyPath: 'name',
                invalidValue: 'super-secret-payload',
                code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(
            value: ['password' => 'leaked-secret'],
            violations: $constraintViolationList,
        );
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(
            'super-secret-payload',
            $json,
            'invalidValue must not propagate to the wire body.',
        );
        $this->assertStringNotContainsString('leaked-secret', $json, 'root must not propagate to the wire body.');
    }

    public function testValidationFailedExceptionDoesNotPropagateMessageTemplate(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'This value should be greater than or equal to 18.',
                messageTemplate: 'This value should be greater than or equal to {{ limit }}.',
                parameters: ['{{ limit }}' => '18'],
                root: null,
                propertyPath: 'age',
                invalidValue: 17,
                code: 'ea4e51d1-3342-48bd-87f1-9e672cd90cad',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertCount(1, $violations);
        $this->assertArrayHasKey(0, $violations);
        $this->assertIsArray($violations[0]);
        $this->assertArrayHasKey('message', $violations[0]);
        $message = $violations[0]['message'];
        $this->assertIsString($message);
        $this->assertSame('This value should be greater than or equal to 18.', $message);
        $this->assertStringNotContainsString('{{ limit }}', $message);

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('{{ limit }}', $json);
    }

    public function testDomainExceptionImplementingInvariantViolationDoesNotProduceViolationsExtension(): void
    {
        $exception = new class ('', 'Account already settled') extends DomainException implements InvariantViolation {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame(422, $problemDetails->status);
        $this->assertSame('invariant-violation', $problemDetails->type);
        $this->assertNotSame('validation-failed', $problemDetails->type);
        $this->assertSame([], $problemDetails->extensions);
        $this->assertArrayNotHasKey('violations', $problemDetails->extensions);
    }

    public function testRfc9457SchemaValidationStillPassesWithViolationsExtension(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                'This value should not be blank.',
                null,
                [],
                null,
                'name',
                '',
                null,
                'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
            new ConstraintViolation(
                'This value is not a valid email address.',
                null,
                [],
                null,
                'email',
                'invalid',
                null,
                'bd79c0ab-ddba-46cc-a703-a7a4b08de310',
            ),
            new ConstraintViolation(
                'This value should be greater than or equal to 18.',
                null,
                [],
                null,
                'age',
                17,
                null,
                'ea4e51d1-3342-48bd-87f1-9e672cd90cad',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $data = \json_decode($json, associative: false, flags: JSON_THROW_ON_ERROR);

        $schemaPath = __DIR__ . '/../../../../Fixtures/Problem/rfc-9457.schema.json';
        $this->assertFileExists($schemaPath, 'RFC 9457 schema fixture must be bundled.');

        $resolvedPath = \realpath($schemaPath);
        $this->assertNotFalse($resolvedPath);
        $schemaRef = (object) ['$ref' => 'file://' . $resolvedPath];

        $validator = new JsonSchemaValidator();
        $validator->validate($data, $schemaRef);

        $this->assertTrue(
            $validator->isValid(),
            \sprintf(
                'Body must validate against RFC 9457 schema; got errors: %s',
                \json_encode($validator->getErrors()),
            ),
        );
    }

    public function testValidationFailedExceptionDetailIsNullAndCorrelationIdInstancePassThrough(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('m', null, [], null, 'p', null, null, 'C'),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertNull($problemDetails->detail);
        $this->assertSame(self::CID, $problemDetails->correlationId);
        $this->assertSame(self::INSTANCE, $problemDetails->instance);
    }

    public function testValidationFailedExceptionViolationPropertyPathPassesThroughVerbatimForNestedPaths(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('m', null, [], null, 'addresses[0].street', null, null, 'C'),
            new ConstraintViolation('m', null, [], null, 'tags[2]', null, null, 'C'),
            new ConstraintViolation('m', null, [], null, 'children.firstName', null, null, 'C'),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertCount(3, $violations);
        $this->assertExpectedViolationEntry($violations, 0, 'addresses[0].street', 'm', 'C');
        $this->assertExpectedViolationEntry($violations, 1, 'tags[2]', 'm', 'C');
        $this->assertExpectedViolationEntry($violations, 2, 'children.firstName', 'm', 'C');
    }

    public function testWrappedValidationFailedExceptionFromHttpExceptionIsUnwrappedAndProducesViolations(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                'This value should not be blank.',
                null,
                [],
                null,
                'name',
                null,
                null,
                'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
            new ConstraintViolation(
                'This value is not a valid email address.',
                null,
                [],
                null,
                'email',
                null,
                null,
                'bd79c0ab-ddba-46cc-a703-a7a4b08de310',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $httpException = new HttpException(422, 'leaked-message-from-wrapper', $validationFailedException);

        $problemDetails = $this->factoryFor()->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame('validation-failed', $problemDetails->type);
        $this->assertSame(422, $problemDetails->status);
        $this->assertSame('Validation failed.', $problemDetails->title);
        $this->assertNull($problemDetails->detail);
        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertCount(2, $violations);
        $this->assertExpectedViolationEntry(
            $violations,
            0,
            'name',
            'This value should not be blank.',
            'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
        );
        $this->assertExpectedViolationEntry(
            $violations,
            1,
            'email',
            'This value is not a valid email address.',
            'bd79c0ab-ddba-46cc-a703-a7a4b08de310',
        );
    }

    public function testWrappedValidationFailedExceptionTitleIsLiteralNotLeakedFromWrapper(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                'This value should not be blank.',
                null,
                [],
                null,
                'name',
                null,
                null,
                'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $httpException = new HttpException(422, (string) $validationFailedException, $validationFailedException);

        $problemDetails = $this->factoryFor()->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame('Validation failed.', $problemDetails->title);
        $this->assertStringNotContainsString('name', $problemDetails->title);
        $this->assertStringNotContainsString('This value should not be blank.', $problemDetails->title);
    }

    public function testDomainExceptionWithValidationFailedExceptionAsPreviousIsRoutedThroughDomainBranch(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('m', null, [], null, 'p', null, null, 'C'),
        ]);

        $previous = new ValidationFailedException(value: null, violations: $constraintViolationList);

        $title = 'Domain wins';
        $domainException = new class ('', $title, [], $previous) extends DomainException implements InvariantViolation {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($domainException, self::CID, self::INSTANCE);

        $this->assertSame('invariant-violation', $problemDetails->type);
        $this->assertSame(422, $problemDetails->status);
        $this->assertSame('Domain wins', $problemDetails->title);
        $this->assertSame([], $problemDetails->extensions);
    }

    public function testValidationFailedExceptionPreservesDuplicateViolationsOnSameField(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                'This value should not be blank.',
                null,
                [],
                null,
                'name',
                null,
                null,
                'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
            new ConstraintViolation(
                'This value is too short.',
                null,
                [],
                null,
                'name',
                null,
                null,
                '9ff3fdc4-b214-49db-8718-39c315e33d45',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertCount(
            2,
            $violations,
            'Two violations on the same field must both surface — no silent dedup.',
        );
        $this->assertExpectedViolationEntry(
            $violations,
            0,
            'name',
            'This value should not be blank.',
            'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
        );
        $this->assertExpectedViolationEntry(
            $violations,
            1,
            'name',
            'This value is too short.',
            '9ff3fdc4-b214-49db-8718-39c315e33d45',
        );
    }

    // -----------------------------------------------------------------------------------------
    // environment-aware `debug` extension
    // -----------------------------------------------------------------------------------------

    public function testDevEnvironmentBodyHasFullDebugExtensionForUnhandledException(): void
    {
        $runtimeException = new RuntimeException('boom');

        $problemDetails = $this->factoryFor('dev')->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $debug = $this->assertFullDebugExtension($problemDetails);
        $this->assertSame('RuntimeException', $debug['exception_class']);
        $this->assertSame('boom', $debug['message']);
        $this->assertStringEndsWith('ProblemDetailsFactoryTest.php', $debug['file']);
        $this->assertGreaterThan(0, $debug['line']);
        $this->assertSame([], $debug['previous_chain']);
        $this->assertSame('debug', \array_key_last($problemDetails->extensions));
    }

    public function testTestEnvironmentBodyHasFullDebugExtensionForDomainException(): void
    {
        $exception = new class ('', 'Bank not found') extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor('test')->fromThrowable($exception, self::CID, self::INSTANCE);

        $debug = $this->assertFullDebugExtension($problemDetails);
        $this->assertSame('Bank not found', $debug['message']);
        $this->assertSame([], $debug['previous_chain']);
    }

    public function testStagingEnvironmentBodyHasMinimalDebugExtension(): void
    {
        $runtimeException = new RuntimeException('boom');

        $problemDetails = $this->factoryFor('staging')->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $debug = $this->assertMinimalDebugExtension($problemDetails);
        $this->assertSame('RuntimeException', $debug['exception_class']);
        $this->assertSame('boom', $debug['message']);
        $this->assertArrayNotHasKey('file', $debug);
        $this->assertArrayNotHasKey('line', $debug);
        $this->assertArrayNotHasKey('previous_chain', $debug);
    }

    public function testProdEnvironmentBodyOmitsDebugExtensionEntirely(): void
    {
        $runtimeException = new RuntimeException('boom');

        $problemDetails = $this->factoryFor('prod')->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $this->assertArrayNotHasKey('debug', $problemDetails->extensions);
    }

    public function testProdEnvironmentUnhandledExceptionTitleIsSafeLiteral(): void
    {
        $leakyMessage = "/abs/path/Module.php SELECT * FROM users WHERE password = 'secret' "
            . '(App\Backoffice\Bank\Internal)';
        $runtimeException = new RuntimeException($leakyMessage);

        $problemDetails = $this->factoryFor('prod')->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $this->assertSame('An unexpected error occurred.', $problemDetails->title);

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('/abs/path/Module.php', $encoded);
        $this->assertStringNotContainsString('SELECT * FROM users', $encoded);
        $this->assertStringNotContainsString('App\\\Backoffice\\\Bank\\\Internal', $encoded);
    }

    public function testStagingEnvironmentDebugIncludesNoFilePathOrLineForUnhandledException(): void
    {
        $runtimeException = new RuntimeException('boom');

        $problemDetails = $this->factoryFor('staging')->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('boom', $encoded);
        $this->assertStringNotContainsString('"file"', $encoded);
        $this->assertStringNotContainsString('"line"', $encoded);
        $this->assertStringNotContainsString('"previous_chain"', $encoded);
    }

    public function testStagingEnvironmentDebugSanitisesAnonymousClassFqcnRemovingFilePathLeak(): void
    {
        // PHP appends `\0/path/to/file.php:line$N` to anonymous-class FQCNs. Without
        // sanitisation, json_encode emits this NUL-suffixed string through `exception_class`,
        // smuggling the file path past AC #4's "no `file`/`line` in staging" rule.
        $exception = new class ('boom') extends RuntimeException {
        };

        $this->assertStringContainsString(
            "\0",
            $exception::class,
            'pre-condition: PHP must embed a NUL byte in the anonymous-class FQCN.',
        );

        $problemDetails = $this->factoryFor('staging')->fromThrowable($exception, self::CID, self::INSTANCE);

        $debug = $this->assertMinimalDebugExtension($problemDetails);
        $this->assertStringNotContainsString(
            "\0",
            $debug['exception_class'],
            'sanitised exception_class must not contain a NUL byte.',
        );
        $this->assertStringEndsWith(
            '@anonymous',
            $debug['exception_class'],
            'sanitised anonymous-class FQCN ends at the @anonymous marker — file path is stripped.',
        );

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(
            ' ',
            $encoded,
            'encoded body must not contain an escaped NUL byte (would precede a leaked file path).',
        );
        $this->assertStringNotContainsString(
            __FILE__,
            $encoded,
            'encoded body must not contain this test file path leaked via anonymous-class FQCN.',
        );
    }

    public function testDevEnvironmentDebugPreviousChainPopulatesForWrappedExceptions(): void
    {
        $runtimeException = new RuntimeException('innermost');
        $logicException = new LogicException('inner', 0, $runtimeException);
        $msg = 'Bank not found';
        $exception = new class ('domain', $msg, [], $logicException) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor('dev')->fromThrowable($exception, self::CID, self::INSTANCE);

        $debug = $this->assertFullDebugExtension($problemDetails);
        $chain = $debug['previous_chain'];
        $this->assertCount(
            2,
            $chain,
            'previous_chain has the inner + innermost; the outer throwable IS the top-level entry.',
        );
        $this->assertSame('LogicException', $chain[0]['exception_class']);
        $this->assertSame('inner', $chain[0]['message']);
        $this->assertSame('RuntimeException', $chain[1]['exception_class']);
        $this->assertSame('innermost', $chain[1]['message']);
    }

    public function testDevEnvironmentDebugPreviousChainCycleGuardIsImplementedViaSplObjectId(): void
    {
        // We avoid mutating `\Exception::$previous` via reflection here because PHP's exception
        // destructor walks the chain on object teardown and a cyclic chain crashes the engine
        // on PHP 8.5. Instead pin the cycle-guard implementation by static inspection of the
        // private `walkPreviousChain` helper: the source must reference `spl_object_id` and a
        // `seen` map to short-circuit on first repeat. This shape is what makes the helper
        // cycle-safe; runtime cycle synthesis is too fragile to test directly.
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);
        $this->assertTrue($reflectionClass->hasMethod('walkPreviousChain'));

        $reflectionMethod = $reflectionClass->getMethod('walkPreviousChain');
        $this->assertTrue($reflectionMethod->isPrivate());

        $fileName = $reflectionClass->getFileName();
        $this->assertIsString($fileName);
        $contents = \file_get_contents($fileName);
        $this->assertIsString($contents);

        $startLine = $reflectionMethod->getStartLine();
        $endLine = $reflectionMethod->getEndLine();
        $this->assertIsInt($startLine);
        $this->assertIsInt($endLine);

        $bodyLines = \array_slice(\explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1);
        $body = \implode("\n", $bodyLines);

        $this->assertStringContainsString(
            'spl_object_id',
            $body,
            'walkPreviousChain must use spl_object_id() to identify visited throwables.',
        );
        $this->assertStringContainsString(
            '$seen',
            $body,
            'walkPreviousChain must keep a `$seen` map of visited throwable ids.',
        );
        $this->assertStringContainsString(
            'break',
            $body,
            'walkPreviousChain must `break` when a previously-seen throwable is revisited.',
        );
    }

    #[DataProvider('provideUnrecognisedEnvironmentValueDefaultsToProdCases')]
    public function testUnrecognisedEnvironmentValueDefaultsToProd(string $environment): void
    {
        $runtimeException = new RuntimeException('x');

        $problemDetails = $this->factoryFor($environment)->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $this->assertArrayNotHasKey('debug', $problemDetails->extensions);
        $this->assertSame('An unexpected error occurred.', $problemDetails->title);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnrecognisedEnvironmentValueDefaultsToProdCases(): iterable
    {
        yield 'ci' => ['ci'];
        yield 'integration' => ['integration'];
        yield 'empty string' => [''];
        yield 'PROD uppercase' => ['PROD'];
        yield 'production' => ['production'];
        yield 'DEV uppercase' => ['DEV'];
        yield 'qa' => ['qa'];
        yield 'leading space dev' => [' dev'];
        yield 'trailing space dev' => ['dev '];
        yield 'tab-prefixed dev' => ["\tdev"];
        yield 'mixed-case Dev' => ['Dev'];
        yield 'mixed-case Test' => ['Test'];
        yield 'mixed-case Staging' => ['Staging'];
    }

    public function testReservedKeyDebugIsStrippedFromDomainExceptionContextInDevEnv(): void
    {
        $context = ['debug' => ['exception_class' => 'spoofed', 'message' => 'spoofed']];
        $exception = new class ('domain', 'Bank not found', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor('dev')->fromThrowable($exception, self::CID, self::INSTANCE);

        // Pin the strip path itself: the only context key is `'debug'`, which RESERVED_KEYS
        // must remove BEFORE withDebug appends the factory-computed map. Post-strip context is
        // empty, so extensions ends up containing a single key — the factory's own `'debug'`.
        // A regression that left the context's spoofed `'debug'` in `$base->extensions` would
        // be overwritten by the trailing spread in `withDebug()` (so `exception_class` would
        // still pass the NotSame check below) but `array_keys` would still equal `['debug']`,
        // which is why the structural assertion is the load-bearing one.
        $this->assertSame(
            ['debug'],
            \array_keys($problemDetails->extensions),
            'context-supplied debug must be stripped pre-wrap; only the factory-computed debug remains.',
        );

        $debug = $this->assertFullDebugExtension($problemDetails);
        $this->assertNotSame(
            'spoofed',
            $debug['exception_class'],
            'Factory-computed debug must not be clobbered by domain context.',
        );
        $this->assertNotSame('spoofed', $debug['message']);
        $this->assertStringContainsString(
            '@anonymous',
            $debug['exception_class'],
            'exception_class is the real anonymous-class FQCN derived from `$throwable::class`.',
        );
        $this->assertSame('Bank not found', $debug['message']);
    }

    public function testReservedKeyDebugIsAbsentInProdEvenWhenSpoofedFromContext(): void
    {
        $context = ['debug' => ['exception_class' => 'spoofed', 'message' => 'spoofed']];
        $exception = new class ('domain', 'Bank not found', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor('prod')->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertArrayNotHasKey('debug', $problemDetails->extensions);
    }

    #[DataProvider('provideDebugExtensionIsAlwaysLastInExtensionsArrayOrderCases')]
    public function testDebugExtensionIsAlwaysLastInExtensionsArrayOrder(string $environment): void
    {
        $context = ['custom_field' => 'value', 'another' => 42];
        $exception = new class ('domain', 'Bank not found', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor($environment)->fromThrowable($exception, self::CID, self::INSTANCE);

        $keys = \array_keys($problemDetails->extensions);
        $this->assertSame('debug', \array_key_last($problemDetails->extensions));
        $this->assertSame(['custom_field', 'another', 'debug'], $keys);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDebugExtensionIsAlwaysLastInExtensionsArrayOrderCases(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
        yield 'staging' => ['staging'];
    }

    public function testValidationFailedDebugCoexistsWithViolationsExtension(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'This value should not be blank.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'name',
                invalidValue: '',
                code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
            new ConstraintViolation(
                message: 'This value is too short.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'email',
                invalidValue: '',
                code: '9ff3fdc4-b214-49db-8718-39c315e33d45',
            ),
        ]);
        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);

        $problemDetails = $this->factoryFor('dev')
            ->fromThrowable($validationFailedException, self::CID, self::INSTANCE)
        ;

        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $this->assertSame(
            'debug',
            \array_key_last($problemDetails->extensions),
            'debug must be the LAST extension key.',
        );
        $keys = \array_keys($problemDetails->extensions);
        $this->assertSame(
            ['violations', 'debug'],
            $keys,
            'violations must precede debug for deterministic key order.',
        );

        $debug = $this->assertFullDebugExtension($problemDetails);
        $this->assertSame(ValidationFailedException::class, $debug['exception_class']);
    }

    public function testHttpExceptionDebugCoexistsWithBridgeBranch(): void
    {
        $httpException = new HttpException(503, 'maintenance');

        $problemDetails = $this->factoryFor('dev')->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame(
            'debug',
            \array_key_last($problemDetails->extensions),
            'debug must be the LAST extension key on the http-exception branch.',
        );
        $debug = $this->assertFullDebugExtension($problemDetails);
        $this->assertSame(HttpException::class, $debug['exception_class']);
        $this->assertSame('maintenance', $debug['message']);
    }

    public function testAccessDeniedAndAuthenticationDebugCoexistWithBridgeBranches(): void
    {
        $accessDeniedException = new AccessDeniedException('Access denied.');
        $problemDetails = $this->factoryFor('dev')
            ->fromThrowable($accessDeniedException, self::CID, self::INSTANCE)
        ;
        $this->assertSame(
            'debug',
            \array_key_last($problemDetails->extensions),
            'debug must be the LAST extension key on the access-denied branch.',
        );
        $accessDeniedDebug = $this->assertFullDebugExtension($problemDetails);
        $this->assertSame(AccessDeniedException::class, $accessDeniedDebug['exception_class']);

        $badCredentialsException = new BadCredentialsException('Bad credentials.');
        $badCredentialsDetails = $this->factoryFor('dev')
            ->fromThrowable($badCredentialsException, self::CID, self::INSTANCE)
        ;
        $this->assertSame(
            'debug',
            \array_key_last($badCredentialsDetails->extensions),
            'debug must be the LAST extension key on the authentication branch.',
        );
        $badCredentialsDebug = $this->assertFullDebugExtension($badCredentialsDetails);
        $this->assertSame(BadCredentialsException::class, $badCredentialsDebug['exception_class']);
    }

    public function testProdEnvironmentDoesNotLeakSensitiveSubstringsFromUnhandledExceptionMessage(): void
    {
        $runtimeException = new RuntimeException('Database error: password=secret123 token=abc987');

        $problemDetails = $this->factoryFor('prod')->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        // Structural pin: prod body must contain ONLY the RFC 9457 minimum keys — no `debug`
        // extension, no leaked context fields. A future regression that smuggles the message
        // through a new extension key would slip past pure substring scans; the key-set
        // assertion catches it.
        $this->assertSame(
            [],
            $problemDetails->extensions,
            'prod must emit no extensions on the unhandled-exception branch.',
        );
        $body = $problemDetails->toArray();
        $this->assertSame(
            ['type', 'title', 'status', 'instance', 'correlation-id'],
            \array_keys($body),
            'prod body keys are exactly the RFC 9457 minimum; no debug, no extensions.',
        );

        $encoded = \json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret123', $encoded);
        $this->assertStringNotContainsString('abc987', $encoded);
        // will add the structured-context redaction denylist; this test pins the
        // title-side defense in depth on the unhandled-exception branch.
    }

    /**
     * every denylisted key (case-insensitive, all four canonical casings) must
     * be stripped from `extensions`; the encoded body must not contain the sentinel value.
     *
     * @param non-empty-string $caseVariant
     */
    #[DataProvider('provideFactoryStripsDenylistedContextKeysFromBodyExtensionsCases')]
    public function testFactoryStripsDenylistedContextKeysFromBodyExtensions(string $caseVariant): void
    {
        $context = [$caseVariant => 'sensitive', 'safe' => 'value'];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertArrayNotHasKey($caseVariant, $problemDetails->extensions);
        $this->assertArrayHasKey('safe', $problemDetails->extensions);
        $this->assertSame('value', $problemDetails->extensions['safe']);

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('sensitive', $encoded);
    }

    /**
     * every denylisted key in {@see RedactionDenylist::KEYS} expanded across the
     * four canonical casings (lower / Title / UPPER / mIxEd). Total rows = 7 × 4 = 28.
     *
     * @return iterable<string, array{0: non-empty-string}>
     */
    public static function provideFactoryStripsDenylistedContextKeysFromBodyExtensionsCases(): iterable
    {
        foreach (RedactionDenylist::KEYS as $canonical) {
            yield $canonical . ' (lower)' => [$canonical];
            yield $canonical . ' (Title)' => [\ucfirst($canonical)];
            yield $canonical . ' (UPPER)' => [\strtoupper($canonical)];
            yield $canonical . ' (mIxEd)' => [self::alternatingCase($canonical)];
            yield $canonical . ' (prefix)' => ['my_' . $canonical];
            yield $canonical . ' (suffix)' => [$canonical . '_hash'];
        }
    }

    /**
     * substring / prefix / whitespace / non-ASCII / numeric-string keys must NOT
     * be stripped. Documents the exact-key-match scope.
     *
     * @param non-empty-string $key
     */
    #[DataProvider('provideFactoryDoesNotStripNonDenylistKeysCases')]
    public function testFactoryDoesNotStripNonDenylistKeys(string $key): void
    {
        $exception = new class ('', 'x', [$key => 'kept']) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertArrayHasKey($key, $problemDetails->extensions);
        $this->assertSame('kept', $problemDetails->extensions[$key]);
    }

    /**
     * non-strip rows pinning exact-match-only scope.
     *
     * @return iterable<string, array{0: non-empty-string}>
     */
    public static function provideFactoryDoesNotStripNonDenylistKeysCases(): iterable
    {
        yield 'prefix-only (pass)' => ['pass'];
        yield 'unrelated (username)' => ['username'];
        yield 'unrelated (public_key)' => ['public_key'];
        yield 'numeric-string (42)' => ['42'];
    }

    /**
     * pins that redaction runs BEFORE the whitelist branch: a denylisted
     * `JsonSerializable` value must NOT survive the `isWhitelistedValue` check.
     */
    public function testFactoryStripsDenylistedKeyEvenWhenValueIsJsonSerializable(): void
    {
        $serializable = new class implements JsonSerializable {
            #[Override]
            public function jsonSerialize(): mixed
            {
                return 'leaked-secret';
            }
        };

        $context = ['password' => $serializable, 'safe' => 'kept'];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertArrayNotHasKey('password', $problemDetails->extensions);
        $this->assertSame(['safe' => 'kept'], $problemDetails->extensions);

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('leaked-secret', $encoded);
    }

    /**
     * denylist strip applies even when the value is a nested array (the array
     * itself goes; the redaction is at the OUTER key level, not at the value structure).
     */
    public function testFactoryStripsDenylistedKeyEvenWhenValueIsArray(): void
    {
        $context = ['authorization' => ['scheme' => 'Bearer', 'value' => 'abc'], 'safe' => 'kept'];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertArrayNotHasKey('authorization', $problemDetails->extensions);
        $this->assertSame(['safe' => 'kept'], $problemDetails->extensions);

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Bearer', $encoded);
        $this->assertStringNotContainsString('abc', $encoded);
    }

    /**
     * single-level scope: redaction does NOT recurse into nested arrays. A
     * `password` key inside `context['user']` survives because the outer `user` key is not
     * denylisted. Documents the limit; future story may extend.
     */
    public function testFactoryRedactionDoesNotApplyRecursivelyToNestedArrays(): void
    {
        $context = ['user' => ['password' => 'sensitive', 'name' => 'alice']];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame($context, $problemDetails->extensions);
    }

    /**
     * declaration order of surviving keys is preserved across the strip.
     */
    public function testFactoryRedactionPreservesDeclarationOrderOfSurvivors(): void
    {
        $context = ['a' => 1, 'password' => 'x', 'b' => 2, 'token' => 'y', 'c' => 3];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame(['a', 'b', 'c'], \array_keys($problemDetails->extensions));
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $problemDetails->extensions);
    }

    /**
     * the two strip layers compose: RESERVED_KEYS strips reserved labels first,
     * then `redactKeys` strips denylisted ones. Both must take effect.
     */
    public function testFactoryRedactionAppliesAfterReservedKeyStrip(): void
    {
        $context = ['type' => 'spoofed', 'password' => 'x', 'safe' => 'v'];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertArrayNotHasKey(
            'type',
            $problemDetails->extensions,
            'RESERVED_KEYS strip must remove the spoofed type key.',
        );
        $this->assertArrayNotHasKey(
            'password',
            $problemDetails->extensions,
            'RedactionDenylist::filter must remove the password key.',
        );
        $this->assertSame(['safe' => 'v'], $problemDetails->extensions);
    }

    /**
     * redaction is environment-agnostic at the body layer. In `'dev'` env the
     * `debug` extension is appended LAST but the denylisted key is still gone
     * from the surviving extensions.
     */
    public function testFactoryRedactionInDevEnvAlsoAppliesToBodyExtensions(): void
    {
        $context = ['password' => 'sensitive', 'safe' => 'kept'];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor('dev')->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertArrayNotHasKey('password', $problemDetails->extensions);
        $this->assertArrayHasKey('safe', $problemDetails->extensions);
        $this->assertArrayHasKey('debug', $problemDetails->extensions);
        $this->assertSame(
            ['safe', 'debug'],
            \array_keys($problemDetails->extensions),
            'denylist key gone, debug appended last.',
        );

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('sensitive', $encoded);
    }

    // -----------------------------------------------------------------------------------------
    // 16 KiB body cap with truncation marker
    // -----------------------------------------------------------------------------------------

    /**
     * pins the cap constant so a regression that loosens it (e.g. to 32 KiB)
     * fails CI loudly. The number is load-bearing across docs / proxies / observability
     * tooling — it is not a free knob.
     */
    public function testBodyByteCapConstantIsExactly16384(): void
    {
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);
        $constant = $reflectionClass->getReflectionConstant('BODY_BYTE_CAP');

        $this->assertNotFalse($constant, 'BODY_BYTE_CAP constant must exist on ProblemDetailsFactory.');
        $this->assertSame(16384, $constant->getValue());
    }

    /**
     * synthetic ValidationFailedException with 500 violations. The encoded
     * body must come in at or under 16384 bytes, carry `truncated: true` as the LAST
     * extension member, and leave the required core fields (`type, title, status, instance,
     * correlation-id`) intact and unmodified.
     */
    public function testBodyCapTruncatesViolationsExtensionWhenSerializedExceeds16KiB(): void
    {
        $violations = [];

        for ($i = 0; $i < 500; ++$i) {
            $violations[] = new ConstraintViolation(
                message: \sprintf('This value should be greater than or equal to %d.', $i),
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: \sprintf('items[%d].value', $i),
                invalidValue: null,
                code: \sprintf('aaaaaaaa-bbbb-cccc-dddd-eeeeeeee%04d', $i),
            );
        }

        $validationFailedException = new ValidationFailedException(
            value: null,
            violations: new ConstraintViolationList($violations),
        );

        $problemDetails = $this->factoryFor()->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(16384, \strlen($encoded), 'encoded body MUST fit within the 16 KiB cap.');

        $this->assertArrayHasKey('truncated', $problemDetails->extensions);
        $this->assertTrue($problemDetails->extensions['truncated']);
        $this->assertSame(
            'truncated',
            \array_key_last($problemDetails->extensions),
            'truncated marker MUST be the LAST extension member.',
        );

        // Core fields preserved verbatim — the cap MUST NOT touch type / title / status /
        // instance / correlation-id.
        $this->assertSame('validation-failed', $problemDetails->type);
        $this->assertSame('Validation failed.', $problemDetails->title);
        $this->assertSame(422, $problemDetails->status);
        $this->assertNull($problemDetails->detail);
        $this->assertSame(self::INSTANCE, $problemDetails->instance);
        $this->assertSame(self::CID, $problemDetails->correlationId);

        // At least some violations survived AND fewer than 500 (otherwise we would not have
        // tripped the cap). Documents that the cap drops the TAIL — the head remains.
        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $survived = $problemDetails->extensions['violations'];
        $this->assertIsArray($survived);
        $this->assertGreaterThan(0, \count($survived), 'at least one head violation must survive truncation.');
        $this->assertLessThan(500, \count($survived), 'cap must drop at least one tail violation given 500 entries.');
    }

    /**
     * truncation is deterministic. Building the same body twice from the same
     * input must yield byte-identical encoded output (no random pop, no hash-order
     * dependency). Pinned by encoding twice and asserting equality.
     */
    public function testBodyCapTruncationIsDeterministic(): void
    {
        $violations = [];

        for ($i = 0; $i < 500; ++$i) {
            $violations[] = new ConstraintViolation(
                message: \sprintf('Message %d for a deterministic-output assertion.', $i),
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: \sprintf('field_%04d', $i),
                invalidValue: null,
                code: \sprintf('determ-%04d', $i),
            );
        }

        $problemDetails = $this->factoryFor()->fromThrowable(
            new ValidationFailedException(value: null, violations: new ConstraintViolationList($violations)),
            self::CID,
            self::INSTANCE,
        );
        $second = $this->factoryFor()->fromThrowable(
            new ValidationFailedException(value: null, violations: new ConstraintViolationList($violations)),
            self::CID,
            self::INSTANCE,
        );

        $encodedFirst = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $encodedSecond = \json_encode($second->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame($encodedFirst, $encodedSecond, 'Cap truncation must be byte-deterministic across runs.');
    }

    /**
     *  happy path: a body that is already under the cap is returned unmodified
     * and carries NO `truncated` marker. Documents that the cap is silent on small bodies.
     */
    public function testBodyCapNoOpWhenBodyAlreadyUnderCap(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('m', null, [], null, 'p', null, null, 'C'),
        ]);

        $problemDetails = $this->factoryFor()->fromThrowable(
            new ValidationFailedException(value: null, violations: $constraintViolationList),
            self::CID,
            self::INSTANCE,
        );

        $this->assertArrayNotHasKey('truncated', $problemDetails->extensions);
        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $survived = $problemDetails->extensions['violations'];
        $this->assertIsArray($survived);
        $this->assertCount(1, $survived);

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(16384, \strlen($encoded));
    }

    /**
     * non-violations extensions are dropped in REVERSE declaration order. Build
     * a DomainException with several extensions where the LAST-declared one (`large_b`) is
     * a multi-KB string; the cap must drop `large_b` first while `large_a` (declared
     * earlier, also large) survives. Documents the deterministic reverse-order rule for
     * the non-violations branch.
     */
    public function testBodyCapTruncatesNonViolationsExtensionsInReverseDeclarationOrder(): void
    {
        // Each ~6 KiB; together ~12 KiB plus envelope overshoots 16 KiB.
        $payloadA = \str_repeat('a', 6000);
        $payloadB = \str_repeat('b', 6000);
        $payloadC = \str_repeat('c', 6000);

        $context = ['large_a' => $payloadA, 'large_b' => $payloadB, 'large_c' => $payloadC];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(16384, \strlen($encoded));
        $this->assertArrayHasKey('truncated', $problemDetails->extensions);
        $this->assertTrue($problemDetails->extensions['truncated']);

        // large_c (declared last) must be dropped first; large_a (declared first) must
        // survive at minimum. large_b's fate depends on whether dropping large_c alone
        // brings the body under the cap; with 6 KiB each plus envelope, dropping large_c
        // leaves ~12 KiB which fits.
        $this->assertArrayNotHasKey(
            'large_c',
            $problemDetails->extensions,
            'last-declared extension must be dropped first.',
        );
        $this->assertArrayHasKey(
            'large_a',
            $problemDetails->extensions,
            'first-declared extension must survive while later ones are dropped.',
        );
        $this->assertSame('truncated', \array_key_last($problemDetails->extensions));
    }

    /**
     * when a body exceeds the cap and BOTH violations and other extensions
     * exist, violations entries are popped FIRST. After violations is empty, only THEN are
     * other extensions dropped in reverse declaration order. Pinned by composing a
     * DomainException carrying a list-shaped extension AFTER a bulky non-list extension —
     * the cap pops list entries from the tail before considering the earlier-declared
     * bulky extension key.
     *
     * NOTE — the AC's "violations first" priority is keyed on the LITERAL extension key
     * `'violations'`, which {@see ProblemDetails::__construct} only emits via the
     * validation-failed branch. {@see DomainException::context()} cannot inject a key
     * named `violations` because `RESERVED_KEYS` strips it (pinned by
     * {@see testReservedKeysAreFilteredFromExtensions}). The validation-failed branch
     * therefore exercises this priority directly via
     * {@see testBodyCapTruncatesViolationsExtensionWhenSerializedExceeds16KiB}; this test
     * documents the broader rule's edge: when the LAST extension is a non-empty list and
     * the body overshoots, the algorithm must still pop list entries from the tail before
     * dropping any extension key. The list here is `entries` (not `violations`), so the
     * cap falls through to step 2b (drop last key) — pinning the negative path:
     * non-violations LIST extensions are NOT specially preserved.
     */
    public function testBodyCapDropsNonViolationsListExtensionsAsAWholeNotEntryByEntry(): void
    {
        // A list-shaped non-violations extension `entries` is the LAST declared key. The
        // cap must drop it WHOLESALE (no entry-level pop) — only `violations` gets the
        // entry-by-entry pop treatment, per the AC's literal "violations first" wording.
        $entries = [];

        for ($i = 0; $i < 500; ++$i) {
            $entries[] = ['index' => $i, 'message' => \sprintf('entry %d for cap probe.', $i)];
        }

        $context = ['head' => 'kept', 'entries' => $entries];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);

        $encoded = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(16384, \strlen($encoded));

        // `entries` (the LAST-declared, large list extension) was dropped wholesale —
        // no partial list survives under a non-`violations` key.
        $this->assertArrayNotHasKey(
            'entries',
            $problemDetails->extensions,
            'non-violations list extensions are dropped as a whole, not entry-by-entry.',
        );
        $this->assertArrayHasKey('head', $problemDetails->extensions);
        $this->assertSame('kept', $problemDetails->extensions['head']);
        $this->assertArrayHasKey('truncated', $problemDetails->extensions);
        $this->assertSame('truncated', \array_key_last($problemDetails->extensions));
    }

    /**
     * escalation path: if the required core fields PLUS the lone `truncated`
     * marker still exceed 16 KiB, the factory throws {@see ProblemBodyTooLargeException}.
     * The listener's outer try/catch then escalates to the static last-resort
     * body. Synthesised by a domain exception whose `title()` returns a 17 KiB string —
     * exotic but the contract MUST be a strict invariant, not a best-effort guideline.
     */
    public function testBodyCapEscalatesViaThrowWhenCoreFieldsAloneExceedCap(): void
    {
        $oversizedTitle = \str_repeat('T', 17000);

        $exception = new class ('', $oversizedTitle) extends DomainException implements NotFound {
        };

        $this->expectException(ProblemBodyTooLargeException::class);
        $this->expectExceptionMessage('Problem Details body exceeds 16384-byte cap on the required core fields alone.');

        $this->factoryFor()->fromThrowable($exception, self::CID, self::INSTANCE);
    }

    /**
     * the survivor extensions and the appended `truncated` marker compose a
     * stable, key-ordered map: head violations (in declaration order from the source list)
     * first, then `truncated` last. Pinned via direct key inspection so a regression that
     * inserts `truncated` mid-extensions or that reorders surviving violations is caught.
     */
    public function testBodyCapPreservesSurvivorOrderAndAppendsTruncatedLast(): void
    {
        $violations = [];

        for ($i = 0; $i < 500; ++$i) {
            $violations[] = new ConstraintViolation(
                message: \sprintf('Order-pin message %d.', $i),
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: \sprintf('field_%04d', $i),
                invalidValue: null,
                code: 'O',
            );
        }

        $problemDetails = $this->factoryFor()->fromThrowable(
            new ValidationFailedException(value: null, violations: new ConstraintViolationList($violations)),
            self::CID,
            self::INSTANCE,
        );

        $keys = \array_keys($problemDetails->extensions);
        $this->assertSame(
            ['violations', 'truncated'],
            $keys,
            'extensions must declare violations first, truncated last — no other keys.',
        );

        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $survived = $problemDetails->extensions['violations'];
        $this->assertIsArray($survived);

        // Every surviving violation must come from the HEAD of the source list — i.e. their
        // `field` values must be the lowest indices, in order. A regression that pops from
        // the head instead of the tail would surface as out-of-order or high-index entries.
        foreach ($survived as $index => $entry) {
            $this->assertIsArray($entry);
            $this->assertArrayHasKey('field', $entry);
            $this->assertSame(\sprintf('field_%04d', $index), $entry['field']);
        }
    }

    /**
     * `applyBodyCap` is a private helper on the final factory class. Pinned via
     * reflection so a regression that promotes it to public / protected (and breaks the
     * single-source-of-truth contract) is caught.
     */
    public function testApplyBodyCapHelperIsPrivate(): void
    {
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);
        $this->assertTrue($reflectionClass->hasMethod('applyBodyCap'));
        $this->assertTrue($reflectionClass->getMethod('applyBodyCap')->isPrivate());

        $returnType = $reflectionClass->getMethod('applyBodyCap')->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame(ProblemDetails::class, $returnType->getName());
    }

    /**
     * narrows a single `BufferingLogger::cleanLogs()` row to the four-key sentinel
     * context shape so individual tests can read fields without `offsetAccess.notFound` noise.
     *
     * @return array{instance: string, correlation_id: string, context_key: string, original_type: string}
     */
    private function assertSentinelLogShape(mixed $logEntry): array
    {
        $this->assertIsArray($logEntry);
        $this->assertArrayHasKey(0, $logEntry);
        $this->assertArrayHasKey(1, $logEntry);
        $this->assertArrayHasKey(2, $logEntry);

        $level = $logEntry[0];
        $message = $logEntry[1];
        $context = $logEntry[2];

        $this->assertSame(LogLevel::NOTICE, $level);
        $this->assertIsString($message);
        $this->assertIsArray($context);

        $this->assertSame(
            ['instance', 'correlation_id', 'context_key', 'original_type'],
            \array_keys($context),
            'Sentinel log context must declare exactly four keys in canonical order.',
        );

        $this->assertArrayHasKey('instance', $context);
        $this->assertArrayHasKey('correlation_id', $context);
        $this->assertArrayHasKey('context_key', $context);
        $this->assertArrayHasKey('original_type', $context);

        $instance = $context['instance'];
        $correlationId = $context['correlation_id'];
        $contextKey = $context['context_key'];
        $originalType = $context['original_type'];

        $this->assertIsString($instance);
        $this->assertIsString($correlationId);
        $this->assertIsString($contextKey);
        $this->assertIsString($originalType);
        $this->assertSame(self::INSTANCE, $instance);
        $this->assertSame(self::CID, $correlationId);

        return [
            'instance' => $instance,
            'correlation_id' => $correlationId,
            'context_key' => $contextKey,
            'original_type' => $originalType,
        ];
    }

    /**
     * @param non-empty-string $value
     *
     * @return non-empty-string
     */
    private static function alternatingCase(string $value): string
    {
        $characters = \str_split($value);

        $alternated = \array_map(
            static fn (string $character, int $index): string => 0 === $index % 2
                ? $character
                : \strtoupper($character),
            $characters,
            \array_keys($characters),
        );

        return $value[0] . \substr(\implode('', $alternated), 1);
    }

    /**
     * narrows the dev/test full-shape `debug` extension for downstream assertions.
     *
     * @return array{
     *     exception_class: string,
     *     message: string,
     *     file: string,
     *     line: int,
     *     previous_chain: list<array{exception_class: string, message: string, file: string, line: int}>,
     * }
     */
    private function assertFullDebugExtension(ProblemDetails $problemDetails): array
    {
        $this->assertArrayHasKey('debug', $problemDetails->extensions);
        $debug = $problemDetails->extensions['debug'];
        $this->assertIsArray($debug);
        $this->assertSame(
            ['exception_class', 'message', 'file', 'line', 'previous_chain'],
            \array_keys($debug),
            'dev/test debug extension must declare all five keys in this exact order.',
        );
        $this->assertArrayHasKey('exception_class', $debug);
        $this->assertArrayHasKey('message', $debug);
        $this->assertArrayHasKey('file', $debug);
        $this->assertArrayHasKey('line', $debug);
        $this->assertArrayHasKey('previous_chain', $debug);
        $this->assertIsString($debug['exception_class']);
        $this->assertIsString($debug['message']);
        $this->assertIsString($debug['file']);
        $this->assertIsInt($debug['line']);
        $this->assertIsArray($debug['previous_chain']);

        /**
         * @var array{
         *     exception_class: string,
         *     message: string,
         *     file: string,
         *     line: int,
         *     previous_chain: list<array{exception_class: string, message: string, file: string, line: int}>,
         * } $debug
         */
        return $debug;
    }

    /**
     * narrows the staging minimal-shape `debug` extension for downstream assertions.
     *
     * @return array{exception_class: string, message: string}
     */
    private function assertMinimalDebugExtension(ProblemDetails $problemDetails): array
    {
        $this->assertArrayHasKey('debug', $problemDetails->extensions);
        $debug = $problemDetails->extensions['debug'];
        $this->assertIsArray($debug);
        $this->assertSame(
            ['exception_class', 'message'],
            \array_keys($debug),
            'staging debug extension must declare exactly exception_class + message — no file, no line, no chain.',
        );
        $this->assertArrayHasKey('exception_class', $debug);
        $this->assertArrayHasKey('message', $debug);
        $this->assertIsString($debug['exception_class']);
        $this->assertIsString($debug['message']);

        /** @var array{exception_class: string, message: string} $debug */
        return $debug;
    }

    /**
     * @param array<mixed, mixed> $violations
     */
    private function assertExpectedViolationEntry(
        array $violations,
        int $index,
        string $expectedField,
        string $expectedMessage,
        string $expectedCode,
    ): void {
        $this->assertArrayHasKey($index, $violations);
        $entry = $violations[$index];
        $this->assertIsArray($entry);
        $this->assertSame(['field', 'message', 'code'], \array_keys($entry));
        $this->assertArrayHasKey('field', $entry);
        $this->assertArrayHasKey('message', $entry);
        $this->assertArrayHasKey('code', $entry);
        $this->assertSame($expectedField, $entry['field']);
        $this->assertSame($expectedMessage, $entry['message']);
        $this->assertSame($expectedCode, $entry['code']);
    }
}
