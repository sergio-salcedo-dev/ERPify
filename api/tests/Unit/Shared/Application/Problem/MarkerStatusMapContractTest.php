<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Problem;

use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use Erpify\Shared\Domain\Exception\Conflict;
use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\Forbidden;
use Erpify\Shared\Domain\Exception\InvalidInput;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;
use Erpify\Shared\Domain\Exception\InvariantViolation;
use Erpify\Shared\Domain\Exception\NotFound;
use Erpify\Shared\Domain\Exception\RateLimited;
use Erpify\Shared\Domain\Exception\Unauthenticated;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

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
