<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Problem;

use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use Erpify\Shared\Domain\Exception\Conflict;
use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\Forbidden;
use Erpify\Shared\Domain\Exception\InvalidInput;
use Erpify\Shared\Domain\Exception\InvariantViolation;
use Erpify\Shared\Domain\Exception\NotFound;
use Erpify\Shared\Domain\Exception\RateLimited;
use Erpify\Shared\Domain\Exception\Unauthenticated;
use JsonSchema\Validator as JsonSchemaValidator;
use JsonSerializable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * @internal
 */
#[CoversClass(ProblemDetailsFactory::class)]
final class ProblemDetailsFactoryTest extends TestCase
{
    private const string CID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string INSTANCE = 'urn:uuid:0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    #[DataProvider('provideMarkers')]
    public function testStatusMappingForEachMarker(
        DomainException $exception,
        int $expectedStatus,
        string $expectedDefaultType,
    ): void {
        $problemDetailsFactory = new ProblemDetailsFactory();

        $problemDetails = $problemDetailsFactory->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame($expectedStatus, $problemDetails->status);
    }

    #[DataProvider('provideMarkers')]
    public function testDefaultTypeForEachMarker(
        DomainException $exception,
        int $expectedStatus,
        string $expectedDefaultType,
    ): void {
        $problemDetailsFactory = new ProblemDetailsFactory();

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
    }

    public function testTypeOverrideWinsWhenNonEmpty(): void
    {
        $exception = new class ('does-not-matter', 'Bank not found') extends DomainException implements NotFound {
            public function type(): string
            {
                return 'bank-not-found';
            }
        };

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame('bank-not-found', $problemDetails->type);
        $this->assertSame(404, $problemDetails->status);
    }

    public function testMultiMarkerFirstDeclaredWinsBothOrders(): void
    {
        $problemDetailsFactory = new ProblemDetailsFactory();

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

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame(500, $problemDetails->status);
        $this->assertSame('domain-error', $problemDetails->type);
    }

    public function testNonDomainThrowableMapsToFiveHundredUnhandledException(): void
    {
        $runtimeException = new RuntimeException('something broke');

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $this->assertSame(500, $problemDetails->status);
        $this->assertSame('unhandled-exception', $problemDetails->type);
        $this->assertSame('something broke', $problemDetails->title);
        $this->assertNull($problemDetails->detail);
        $this->assertSame([], $problemDetails->extensions);
    }

    public function testNonDomainThrowableWithEmptyMessageFallsBackToSafeLiteral(): void
    {
        $runtimeException = new RuntimeException('');

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $this->assertSame('An unexpected error occurred.', $problemDetails->title);
    }

    public function testCorrelationIdAndInstancePassThroughVerbatim(): void
    {
        $problemDetailsFactory = new ProblemDetailsFactory();
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

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame('Bank not found', $problemDetails->title);
        $this->assertNull($problemDetails->detail);
    }

    public function testContextScalarArrayJsonSerializableValuesPassThrough(): void
    {
        $serializable = new class implements JsonSerializable {
            /**
             * @return array{nested: bool}
             */
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

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame($context, $problemDetails->extensions);
    }

    public function testContextNonWhitelistedValuesAreSilentlyDropped(): void
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

            $result = (new ProblemDetailsFactory())->fromThrowable($exception, self::CID, self::INSTANCE);

            $this->assertSame(['safe' => 1], $result->extensions);
            $this->assertArrayNotHasKey('closure', $result->extensions);
            $this->assertArrayNotHasKey('resource', $result->extensions);
            $this->assertArrayNotHasKey('object', $result->extensions);
        } finally {
            \fclose($resource);
        }
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

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame(['safe_key' => 'ok'], $problemDetails->extensions);
    }

    public function testNonDomainThrowableYieldsEmptyExtensions(): void
    {
        $runtimeException = new RuntimeException('boom');

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $this->assertSame([], $problemDetails->extensions);
    }

    public function testMarkerStatusMapHasExactlyTheCanonicalSevenEntries(): void
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
        ];

        $this->assertSame($expected, $value, 'MARKER_STATUS_MAP must contain exactly the seven canonical marker→status entries in canonical order.');
    }

    public function testMarkerDefaultTypeMapHasExactlyTheCanonicalSevenEntries(): void
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
        ];

        $this->assertSame($expected, $value, 'MARKER_DEFAULT_TYPE_MAP must contain exactly the seven canonical marker→default-type entries in canonical order.');
    }

    /**
     * Story 1.5 narrows the original `'Symfony\\'` ban (Story 1.3) to an explicit allowlist of three Symfony imports
     * (`HttpKernel\Exception\HttpExceptionInterface`, `Security\Core\Exception\AccessDeniedException`,
     * `Security\Core\Exception\AuthenticationException`). Every other Symfony namespace is still banned via the
     * narrower prefix list below.
     */
    public function testSourceFileContainsNoBannedImports(): void
    {
        $sourcePath = \dirname(__DIR__, 5) . '/src/Shared/Application/Problem/ProblemDetailsFactory.php';
        $this->assertFileExists($sourcePath);

        $contents = \file_get_contents($sourcePath);
        $this->assertNotFalse($contents);

        $banned = [
            'Doctrine\\',
            'Psr\Http\\',
            'Symfony\Component\HttpFoundation\\',
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
                \sprintf('ProblemDetailsFactory.php must not import any %s symbol — factory stays mapping-focused.', $prefix),
            );
        }
    }

    public function testFactoryHasNoConstructorAndIsFinal(): void
    {
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);

        $this->assertTrue($reflectionClass->isFinal(), 'ProblemDetailsFactory must be final.');
        $this->assertNotInstanceOf(
            ReflectionMethod::class,
            $reflectionClass->getConstructor(),
            'ProblemDetailsFactory must have no constructor in Story 1.3 — Epic 3 may inject seam strategies later.',
        );

        // Seam methods exist with their no-op shape so Stories 3.2/3.3 can fill them. Visibility
        // is private because the class is final (Rector privatizes protected methods on final
        // classes); Stories 3.2/3.3 will switch to composition or relax `final` if extension is
        // needed at that point.
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
            public function jsonSerialize(): array
            {
                return ['ok' => true];
            }
        };

        $context = ['payload' => $serializable, 'count' => 7];
        $exception = new class ('', 'x', $context) extends DomainException implements NotFound {
        };

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($exception, self::CID, self::INSTANCE);

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

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame($status, $problemDetails->status);
        $this->assertSame($expectedType, $problemDetails->type);
    }

    /**
     * Story 1.5 — HttpExceptionInterface branch: status from getStatusCode(), type from HTTP_STATUS_TYPE_MAP.
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

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame(410, $problemDetails->status);
        $this->assertSame('http-error', $problemDetails->type);
        $this->assertSame('gone', $problemDetails->title);
    }

    public function testHttpExceptionWithEmptyMessageFallsBackToSafeLiteral(): void
    {
        $httpException = new HttpException(503, '');

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame('An HTTP error occurred.', $problemDetails->title);
    }

    public function testHttpExceptionTitleComesFromGetMessageVerbatim(): void
    {
        $httpException = new HttpException(404, 'Resource not found by id 42');

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame('Resource not found by id 42', $problemDetails->title);
    }

    public function testHttpExceptionDetailIsNullAndExtensionsEmpty(): void
    {
        $httpException = new HttpException(404, 'gone');

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertNull($problemDetails->detail);
        $this->assertSame([], $problemDetails->extensions);
    }

    public function testAccessDeniedExceptionMapsToForbidden(): void
    {
        $accessDeniedException = new AccessDeniedException();

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($accessDeniedException, self::CID, self::INSTANCE);

        $this->assertSame(403, $problemDetails->status);
        $this->assertSame('forbidden', $problemDetails->type);
        $this->assertSame('Access Denied.', $problemDetails->title);
    }

    public function testAccessDeniedExceptionTitleFallsBackOnEmptyMessage(): void
    {
        $accessDeniedException = new AccessDeniedException('');

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($accessDeniedException, self::CID, self::INSTANCE);

        $this->assertSame('Access denied.', $problemDetails->title);
    }

    public function testAccessDeniedExceptionWithCustomMessage(): void
    {
        $accessDeniedException = new AccessDeniedException('Bank not authorized.');

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($accessDeniedException, self::CID, self::INSTANCE);

        $this->assertSame('Bank not authorized.', $problemDetails->title);
        $this->assertSame(403, $problemDetails->status);
        $this->assertSame('forbidden', $problemDetails->type);
    }

    public function testAuthenticationExceptionMapsToUnauthenticated(): void
    {
        $authenticationException = new class ('Token expired.') extends AuthenticationException {
        };

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($authenticationException, self::CID, self::INSTANCE);

        $this->assertSame(401, $problemDetails->status);
        $this->assertSame('unauthenticated', $problemDetails->type);
        $this->assertSame('Token expired.', $problemDetails->title);
    }

    public function testAuthenticationExceptionTitleFallsBackOnEmptyMessage(): void
    {
        $authenticationException = new class ('') extends AuthenticationException {
        };

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($authenticationException, self::CID, self::INSTANCE);

        $this->assertSame('Authentication required.', $problemDetails->title);
    }

    public function testDomainExceptionTakesPrecedenceOverSymfonyBranches(): void
    {
        // Artificial multi-implementer: DomainException + HttpExceptionInterface (the same Symfony interface
        // the factory's branch 4 keys on). The DomainException branch must win — pins the branch order
        // in fromThrowable() (Story 1.5 places Symfony branches AFTER the DomainException branch).
        $exception = new class ('', 'Bank not found') extends DomainException implements NotFound, \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface {
            public function getStatusCode(): int
            {
                // Returns a status that disagrees with NotFound's 404 — if precedence regressed and the
                // HttpExceptionInterface branch fired instead, the assertion below would catch it.
                return 418;
            }

            /**
             * @return array<string, string>
             */
            public function getHeaders(): array
            {
                return [];
            }
        };

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($exception, self::CID, self::INSTANCE);

        // Status 404 (from marker — NOT 418 from getStatusCode), type 'not-found' (from MARKER_DEFAULT_TYPE_MAP — NOT 'http-error').
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

        $this->assertSame($expected, $value, 'HTTP_STATUS_TYPE_MAP must contain exactly the seven canonical status→type entries in canonical order.');
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
            $derived[$status] = $markerType[$marker];
        }

        \ksort($derived);
        \ksort($httpType);

        $this->assertSame(
            $derived,
            $httpType,
            'HTTP_STATUS_TYPE_MAP must use the same type strings as MARKER_DEFAULT_TYPE_MAP for the same status, '
            . 'so PWA `type`-only routing (FR44) is uniform across DomainException markers, Security Core, and Symfony HttpException sources.',
        );
    }

    public function testRuntimeExceptionStillFallsThroughToUnhandledException(): void
    {
        // CRITICAL: use the GLOBAL \RuntimeException, NOT Symfony\Component\Security\Core\Exception\RuntimeException
        // (which AccessDeniedException extends — easy footgun).
        $runtimeException = new RuntimeException('boom');

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($runtimeException, self::CID, self::INSTANCE);

        $this->assertSame(500, $problemDetails->status);
        $this->assertSame('unhandled-exception', $problemDetails->type);
        $this->assertSame('boom', $problemDetails->title);
    }

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
                plural: null,
                code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
            new ConstraintViolation(
                message: 'This value is not a valid email address.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'email',
                invalidValue: 'invalid',
                plural: null,
                code: 'bd79c0ab-ddba-46cc-a703-a7a4b08de310',
            ),
            new ConstraintViolation(
                message: 'This value should be greater than or equal to 18.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'age',
                invalidValue: 17,
                plural: null,
                code: 'ea4e51d1-3342-48bd-87f1-9e672cd90cad',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(
            value: ['name' => '', 'email' => 'invalid', 'age' => 17],
            violations: $constraintViolationList,
        );

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

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
                plural: null,
                code: 'C',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertMatchesRegularExpression(
            '/"violations":\[\{"field":"p","message":"m","code":"C"\}\]/',
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
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

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
                plural: null,
                code: '',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertArrayHasKey(0, $violations);
        $this->assertIsArray($violations[0]);
        $this->assertArrayHasKey('code', $violations[0]);
        $this->assertSame('', $violations[0]['code']);
        $this->assertNotNull($violations[0]['code']);
    }

    public function testValidationFailedExceptionViolationPropertyPathPassesThroughEvenWhenEmpty(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'm',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: '',
                invalidValue: null,
                plural: null,
                code: 'C',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertCount(1, $violations);
        $this->assertArrayHasKey(0, $violations);
        $this->assertIsArray($violations[0]);
        $this->assertArrayHasKey('field', $violations[0]);
        $this->assertSame('', $violations[0]['field']);
    }

    public function testValidationFailedExceptionWithEmptyListProducesEmptyViolationsArray(): void
    {
        $constraintViolationList = new ConstraintViolationList([]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

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
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"violations":[{', $json, 'violations must serialize as a JSON array, not an object.');
        $this->assertSame(1, \preg_match('/"violations":\[\{/', $json));
        $this->assertDoesNotMatchRegularExpression('/"violations":\{/', $json);
    }

    public function testValidationFailedExceptionTitleIsTheLiteralValidationFailedNotTheMessage(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('This value should not be blank.', null, [], null, 'name', '', null, 'c1051bb4-d103-4f74-8988-acbcafc7fdc3'),
            new ConstraintViolation('This value is not a valid email address.', null, [], null, 'email', 'invalid', null, 'bd79c0ab-ddba-46cc-a703-a7a4b08de310'),
            new ConstraintViolation('This value should be greater than or equal to 18.', null, [], null, 'age', 17, null, 'ea4e51d1-3342-48bd-87f1-9e672cd90cad'),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

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
                plural: null,
                code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: ['password' => 'leaked-secret'], violations: $constraintViolationList);
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('super-secret-payload', $json, 'invalidValue must not propagate to the wire body.');
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
                plural: null,
                code: 'ea4e51d1-3342-48bd-87f1-9e672cd90cad',
            ),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

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

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($exception, self::CID, self::INSTANCE);

        $this->assertSame(422, $problemDetails->status);
        $this->assertSame('invariant-violation', $problemDetails->type);
        $this->assertNotSame('validation-failed', $problemDetails->type);
        $this->assertSame([], $problemDetails->extensions);
        $this->assertArrayNotHasKey('violations', $problemDetails->extensions);
    }

    public function testRfc9457SchemaValidationStillPassesWithViolationsExtension(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('This value should not be blank.', null, [], null, 'name', '', null, 'c1051bb4-d103-4f74-8988-acbcafc7fdc3'),
            new ConstraintViolation('This value is not a valid email address.', null, [], null, 'email', 'invalid', null, 'bd79c0ab-ddba-46cc-a703-a7a4b08de310'),
            new ConstraintViolation('This value should be greater than or equal to 18.', null, [], null, 'age', 17, null, 'ea4e51d1-3342-48bd-87f1-9e672cd90cad'),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

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
            \sprintf('Body must validate against RFC 9457 schema; got errors: %s', \json_encode($validator->getErrors())),
        );
    }

    public function testValidationFailedExceptionDetailIsNullAndCorrelationIdInstancePassThrough(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('m', null, [], null, 'p', null, null, 'C'),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

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
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

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
            new ConstraintViolation('This value should not be blank.', null, [], null, 'name', null, null, 'c1051bb4-d103-4f74-8988-acbcafc7fdc3'),
            new ConstraintViolation('This value is not a valid email address.', null, [], null, 'email', null, null, 'bd79c0ab-ddba-46cc-a703-a7a4b08de310'),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $httpException = new HttpException(422, 'leaked-message-from-wrapper', $validationFailedException);

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame('validation-failed', $problemDetails->type);
        $this->assertSame(422, $problemDetails->status);
        $this->assertSame('Validation failed.', $problemDetails->title);
        $this->assertNull($problemDetails->detail);
        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertCount(2, $violations);
        $this->assertExpectedViolationEntry($violations, 0, 'name', 'This value should not be blank.', 'c1051bb4-d103-4f74-8988-acbcafc7fdc3');
        $this->assertExpectedViolationEntry($violations, 1, 'email', 'This value is not a valid email address.', 'bd79c0ab-ddba-46cc-a703-a7a4b08de310');
    }

    public function testWrappedValidationFailedExceptionTitleIsLiteralNotLeakedFromWrapper(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('This value should not be blank.', null, [], null, 'name', null, null, 'c1051bb4-d103-4f74-8988-acbcafc7fdc3'),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $httpException = new HttpException(422, (string) $validationFailedException, $validationFailedException);

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($httpException, self::CID, self::INSTANCE);

        $this->assertSame('Validation failed.', $problemDetails->title);
        $this->assertStringNotContainsString('name', $problemDetails->title);
        $this->assertStringNotContainsString('This value should not be blank.', $problemDetails->title);
    }

    public function testDomainExceptionWithValidationFailedExceptionAsPreviousIsRoutedThroughDomainExceptionBranch(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('m', null, [], null, 'p', null, null, 'C'),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);

        $domainException = new class ('', 'Domain wins', [], $validationFailedException) extends DomainException implements InvariantViolation {
        };

        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($domainException, self::CID, self::INSTANCE);

        $this->assertSame('invariant-violation', $problemDetails->type);
        $this->assertSame(422, $problemDetails->status);
        $this->assertSame('Domain wins', $problemDetails->title);
        $this->assertSame([], $problemDetails->extensions);
    }

    public function testValidationFailedExceptionPreservesDuplicateViolationsOnSameField(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('This value should not be blank.', null, [], null, 'name', null, null, 'c1051bb4-d103-4f74-8988-acbcafc7fdc3'),
            new ConstraintViolation('This value is too short.', null, [], null, 'name', null, null, '9ff3fdc4-b214-49db-8718-39c315e33d45'),
        ]);

        $validationFailedException = new ValidationFailedException(value: null, violations: $constraintViolationList);
        $problemDetails = (new ProblemDetailsFactory())->fromThrowable($validationFailedException, self::CID, self::INSTANCE);

        $this->assertArrayHasKey('violations', $problemDetails->extensions);
        $violations = $problemDetails->extensions['violations'];
        $this->assertIsArray($violations);
        $this->assertCount(2, $violations, 'Two violations on the same field must both surface — no silent dedup.');
        $this->assertExpectedViolationEntry($violations, 0, 'name', 'This value should not be blank.', 'c1051bb4-d103-4f74-8988-acbcafc7fdc3');
        $this->assertExpectedViolationEntry($violations, 1, 'name', 'This value is too short.', '9ff3fdc4-b214-49db-8718-39c315e33d45');
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
