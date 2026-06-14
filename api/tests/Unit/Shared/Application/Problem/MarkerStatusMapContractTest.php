<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Problem;

use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use Erpify\Shared\Domain\Exception\ClientError;
use Erpify\Shared\Domain\Exception\Conflict;
use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\Forbidden;
use Erpify\Shared\Domain\Exception\InvalidInput;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;
use Erpify\Shared\Domain\Exception\InvariantViolation;
use Erpify\Shared\Domain\Exception\NotFound;
use Erpify\Shared\Domain\Exception\RateLimited;
use Erpify\Shared\Domain\Exception\Unauthenticated;
use Erpify\Tests\Support\ApiSourceFiles;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Throwable;

/**
 * Per-marker contract pin.
 *
 * The data provider reflects directly over {@see ProblemDetailsFactory::MARKER_STATUS_MAP}
 * and `MARKER_DEFAULT_TYPE_MAP` so the test cannot drift from the production constants:
 * adding or removing a marker without matching code-side updates makes this contract
 * fail. The eight canonical markers are the marker-interface set:
 * {@see NotFound}, {@see Conflict}, {@see Forbidden}, {@see Unauthenticated},
 * {@see InvariantViolation}, {@see InvalidInput}, {@see RateLimited},
 * {@see InvalidSearchCriteria}.
 *
 * Co-located alongside {@see ProblemDetailsFactoryTest} under
 * `api/tests/Unit/Shared/Application/Problem/`.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ProblemDetailsFactory::class)]
final class MarkerStatusMapContractTest extends TestCase
{
    /**
     * The eight canonical marker interfaces. Pinned here as a STATIC list so any addition to
     * `MARKER_STATUS_MAP` without updating this canonical-set assertion fails the
     * `testMarkerStatusMapContainsExactlyTheCanonicalEight` guard. The data-provider-driven
     * row tests still iterate from the constants directly — the canonical list is the
     * "should equal X" half of the equality, not the source of the rows.
     */
    private const array CANONICAL_MARKERS = [
        NotFound::class,
        Conflict::class,
        Forbidden::class,
        Unauthenticated::class,
        InvariantViolation::class,
        InvalidInput::class,
        RateLimited::class,
        InvalidSearchCriteria::class,
    ];

    private const string CID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string INSTANCE = 'urn:uuid:0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    /**
     * Per-row pin (data provider reads directly from {@see ProblemDetailsFactory::MARKER_STATUS_MAP}
     * and `MARKER_DEFAULT_TYPE_MAP` via reflection — see {@see markerRowsFromConstants}). For
     * each entry in the constant the factory MUST yield a Problem Details body with the matching
     * `status` and (when no explicit `type` is supplied by the exception) the matching default
     * `type` literal.
     */
    #[DataProvider('provideEachMarkerMapsToExpectedStatusAndDefaultTypeCases')]
    public function testEachMarkerMapsToExpectedStatusAndDefaultType(
        string $markerClass,
        int $expectedStatus,
        string $expectedDefaultType,
    ): void {
        $problemDetailsFactory = new ProblemDetailsFactory('prod', new NullLogger());

        $problemDetails = $problemDetailsFactory->fromThrowable(
            $this->exceptionImplementingMarker($markerClass),
            self::CID,
            self::INSTANCE,
        );

        $this->assertSame(
            $expectedStatus,
            $problemDetails->status,
            \sprintf('Marker %s should map to HTTP status %d.', $markerClass, $expectedStatus),
        );
        $this->assertSame(
            $expectedDefaultType,
            $problemDetails->type,
            \sprintf('Marker %s should map to default type %s.', $markerClass, $expectedDefaultType),
        );
    }

    /**
     * Reflection-driven data provider — the single source of truth for the per-row test rows
     * is {@see ProblemDetailsFactory::MARKER_STATUS_MAP} (and the matching
     * `MARKER_DEFAULT_TYPE_MAP`). Reading via {@see ReflectionClass::getConstant} works for
     * private constants, so the production constants stay private.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function provideEachMarkerMapsToExpectedStatusAndDefaultTypeCases(): iterable
    {
        $statusMap = self::reflectConstant('MARKER_STATUS_MAP');
        $defaultTypeMap = self::reflectConstant('MARKER_DEFAULT_TYPE_MAP');

        foreach ($statusMap as $markerClass => $status) {
            if (!\is_int($status)) {
                throw new LogicException(\sprintf(
                    'MARKER_STATUS_MAP[%s] must be an int, got %s.',
                    (string) $markerClass,
                    \get_debug_type($status),
                ));
            }

            if (!\array_key_exists($markerClass, $defaultTypeMap)) {
                throw new LogicException(\sprintf(
                    'MARKER_DEFAULT_TYPE_MAP missing entry for marker %s — both maps must agree.',
                    $markerClass,
                ));
            }

            $defaultType = $defaultTypeMap[$markerClass];

            if (!\is_string($defaultType)) {
                throw new LogicException(\sprintf(
                    'MARKER_DEFAULT_TYPE_MAP[%s] must be a string, got %s.',
                    $markerClass,
                    \get_debug_type($defaultType),
                ));
            }

            yield $markerClass => [$markerClass, $status, $defaultType];
        }
    }

    /**
     * Pins that the factory's mapping constant array contains exactly those eight marker classes.
     * Set-equality (not order-sensitive) so a marker addition that drifts the constant from
     * the canonical list fails fast — without forcing a particular declaration order
     * (`testMarkerOrderingFollowsImplementsClause` already pins the ordering separately).
     */
    public function testMarkerStatusMapContainsExactlyTheCanonicalEight(): void
    {
        $statusMap = self::reflectConstant('MARKER_STATUS_MAP');

        $this->assertCount(
            8,
            $statusMap,
            'MARKER_STATUS_MAP must contain exactly the eight canonical markers.',
        );

        $actualKeys = \array_keys($statusMap);

        \sort($actualKeys);
        $expectedKeys = self::CANONICAL_MARKERS;
        \sort($expectedKeys);

        $this->assertSame(
            $expectedKeys,
            $actualKeys,
            'MARKER_STATUS_MAP keys must equal the canonical marker set.',
        );
    }

    /**
     * Sister pin to {@see testMarkerStatusMapContainsExactlyTheCanonicalSeven}: the default-type
     * map MUST mirror the status map's key set so every marker has a default `type` literal
     * for PWA `type`-only routing.
     */
    public function testMarkerDefaultTypeMapKeysMatchMarkerStatusMapKeys(): void
    {
        $statusMap = self::reflectConstant('MARKER_STATUS_MAP');
        $defaultTypeMap = self::reflectConstant('MARKER_DEFAULT_TYPE_MAP');

        $statusKeys = \array_keys($statusMap);
        $defaultTypeKeys = \array_keys($defaultTypeMap);

        \sort($statusKeys);
        \sort($defaultTypeKeys);

        $this->assertSame(
            $statusKeys,
            $defaultTypeKeys,
            'MARKER_DEFAULT_TYPE_MAP keys must equal MARKER_STATUS_MAP keys.',
        );
    }

    /**
     * Couples the RFC 9457 marker→status table to observability. A marker is a
     * {@see ClientError} — and so is suppressed from Sentry by
     * {@see \Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventFilter} — IF AND ONLY IF
     * its mapped HTTP status is 4xx. This encodes the rule "4xx are expected client errors, 5xx
     * are server faults", not the weaker "every marker we have today is 4xx": the day a 5xx
     * marker joins the map it fails UNLESS that marker deliberately does NOT extend ClientError,
     * forcing a conscious "should this reach Sentry?" decision rather than silently leaking a 4xx
     * (noise) or hiding a 5xx (lost error).
     */
    public function testMarkerIsClientErrorIffStatusIs4xx(): void
    {
        $statusMap = self::reflectConstant('MARKER_STATUS_MAP');

        foreach ($statusMap as $markerClass => $status) {
            $isClientStatus = \is_int($status) && $status >= 400 && $status < 500;

            $this->assertSame(
                $isClientStatus,
                \is_subclass_of((string) $markerClass, ClientError::class),
                \sprintf(
                    'Marker %s maps to status %s, so it must %sextend ClientError.',
                    (string) $markerClass,
                    \is_int($status) ? (string) $status : \get_debug_type($status),
                    $isClientStatus ? '' : 'NOT ',
                ),
            );
        }
    }

    /**
     * Dual-marker gate: every CONCRETE class under `api/src` implementing {@see Throwable} and
     * two or more of the canonical markers MUST pin its `type` explicitly, so the wire
     * `type`/`status` never silently depend on implements-clause order
     * (`firstMatchingMarker` resolves by `class_implements` order when `type()` is empty).
     *
     * Detection is reflection-metadata ONLY — the file walk autoloads each class (definition,
     * never construction) and no inspected class is instantiated or invoked. "Explicit type"
     * means the class hierarchy below the {@see DomainException} base contributes either:
     *   - a `TYPE` class constant holding a non-empty string (the codebase convention — the
     *     base declares no `TYPE`, so any visible one was pinned by a subclass), or
     *   - a `type()` override declared by a class other than {@see DomainException} (the base
     *     method just echoes the constructor argument, whose empty-string default is the
     *     "no explicit type" representation).
     */
    public function testDualMarkerThrowablesDeclareAnExplicitType(): void
    {
        $markerClasses = \array_keys(self::reflectConstant('MARKER_STATUS_MAP'));
        $scanned = 0;
        $violations = [];

        foreach ($this->concreteThrowableClassesUnderApiSrc() as $reflectionClass) {
            ++$scanned;

            $markers = \array_values(\array_intersect($reflectionClass->getInterfaceNames(), $markerClasses));

            if (\count($markers) < 2) {
                continue;
            }

            if ($this->declaresExplicitType($reflectionClass)) {
                continue;
            }

            $violations[] = \sprintf(
                '%s implements markers [%s] without an explicit type — declare a non-empty TYPE '
                . 'constant or override type(), so the mapped status/type cannot silently follow '
                . 'the implements-clause order.',
                $reflectionClass->getName(),
                \implode(', ', $markers),
            );
        }

        $this->assertGreaterThan(0, $scanned, 'Dual-marker gate scanned zero throwable classes under api/src.');
        $this->assertSame(
            [],
            $violations,
            "Dual-marker exceptions without an explicit type:\n" . \implode("\n", $violations),
        );
    }

    /**
     * Walks `api/src` and yields a {@see ReflectionClass} for every concrete (non-abstract,
     * non-interface) class implementing {@see Throwable}. FQCNs are derived from the PSR-4
     * mapping `Erpify\` → `src/`; files whose path does not resolve to a loadable class are
     * skipped. The directory sweep is the shared {@see ApiSourceFiles} walk that
     * {@see ErrorContractGateTest} also uses; this gate layers FQCN + reflection on top.
     *
     * @return iterable<ReflectionClass<object>>
     */
    private function concreteThrowableClassesUnderApiSrc(): iterable
    {
        $srcRoot = ApiSourceFiles::root();

        foreach (ApiSourceFiles::phpFiles($srcRoot) as $file) {
            $relative = \substr($file->getPathname(), \strlen($srcRoot) + 1, -\strlen('.php'));
            $fqcn = 'Erpify\\' . \str_replace('/', '\\', $relative);

            if (!\class_exists($fqcn)) {
                continue;
            }

            $reflectionClass = new ReflectionClass($fqcn);

            if ($reflectionClass->isAbstract()) {
                continue;
            }

            if ($reflectionClass->isInterface()) {
                continue;
            }

            if (!$reflectionClass->implementsInterface(Throwable::class)) {
                continue;
            }

            yield $reflectionClass;
        }
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     */
    private function declaresExplicitType(ReflectionClass $reflectionClass): bool
    {
        $typeConstant = $reflectionClass->getReflectionConstant('TYPE');

        if (false !== $typeConstant) {
            $value = $typeConstant->getValue();

            if (\is_string($value) && '' !== $value) {
                return true;
            }
        }

        return $reflectionClass->hasMethod('type')
            && DomainException::class !== $reflectionClass->getMethod('type')->getDeclaringClass()->getName();
    }

    /**
     * Builds a minimal anonymous {@see DomainException} that implements the requested marker.
     * Each branch mirrors the eight canonical markers; the `match` is exhaustive and falls through
     * to a {@see LogicException} so a future marker addition without a corresponding branch
     * here surfaces immediately rather than silently skipping a row.
     */
    private function exceptionImplementingMarker(string $markerClass): DomainException
    {
        return match ($markerClass) {
            NotFound::class => new class ('', 'x') extends DomainException implements NotFound {
            },
            Conflict::class => new class ('', 'x') extends DomainException implements Conflict {
            },
            Forbidden::class => new class ('', 'x') extends DomainException implements Forbidden {
            },
            Unauthenticated::class => new class ('', 'x') extends DomainException implements Unauthenticated {
            },
            InvariantViolation::class => new class ('', 'x') extends DomainException implements InvariantViolation {
            },
            InvalidInput::class => new class ('', 'x') extends DomainException implements InvalidInput {
            },
            RateLimited::class => new class ('', 'x') extends DomainException implements RateLimited {
            },
            // phpcs:ignore Generic.Files.LineLength.TooLong
            InvalidSearchCriteria::class => new class ('', 'x') extends DomainException implements InvalidSearchCriteria {
            },
            default => throw new LogicException(\sprintf(
                'No anonymous-class branch for marker %s — add it when extending the marker set.',
                $markerClass,
            )),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function reflectConstant(string $name): array
    {
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);
        $constant = $reflectionClass->getConstant($name);

        if (!\is_array($constant)) {
            throw new LogicException(\sprintf(
                'ProblemDetailsFactory::%s is not an array constant.',
                $name,
            ));
        }

        /** @var array<string, mixed> $constant */
        return $constant;
    }
}
