<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Serialization;

use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Unit\Shared\Serialization\Fixture\NonFlatResourceStub;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;

/**
 * Architecture invariant guarding {@see \Erpify\Shared\Serialization\Infrastructure\ResourceNormalizer}.
 *
 * The normalizer serializes resource DTOs with NO serializer groups and NO format context: whatever
 * public shape a DTO exposes is emitted verbatim. The two byte-stability nets that `#[Groups]` (an
 * allowlist) and the `#[Serializer\Context]` ATOM pin used to provide are therefore gone, so the
 * contract is enforced structurally here: every class under an `Application/Resource/` directory must
 * be a flat, immutable, scalar-only DTO.
 *
 * This closes both latent risks at once:
 *   - a raw `DateTimeImmutable` (or any object) reaching the normalizer would emit a non-ATOM /
 *     nested shape with nothing to catch it — scalar-only makes that impossible;
 *   - ungrouped normalization serializes every public property AND getter — scalar-only plus no
 *     public method beyond the constructor means there is no object graph to leak.
 *
 * The ATOM string VALUE is asserted where it is produced — the per-view mapper tests
 * (e.g. `BankResourceMapperTest`) — because the date field is a pre-formatted `string` by the time
 * it reaches a DTO here.
 *
 * @internal
 */
#[CoversNothing]
final class ResourceDtoContractTest extends TestCase
{
    /**
     * Floor guarding against a broken scan path silently emptying the sweep (a vacuous pass). Kept
     * below the current resource count so it does not break on a legitimate removal, yet high enough
     * to catch a path typo that matches nothing.
     */
    private const int MINIMUM_EXPECTED_RESOURCES = 4;

    private const string RESOURCE_DIR_SEGMENT = '/Application/Resource/';

    /**
     * @var list<string>
     */
    private const array FLAT_SCALAR_TYPES = ['string', 'int', 'float', 'bool'];

    public function testResourceDtoSweepIsNotVacuous(): void
    {
        $this->assertGreaterThanOrEqual(
            self::MINIMUM_EXPECTED_RESOURCES,
            \count($this->resourceDtoClasses()),
            'Resource DTO discovery found fewer classes than expected — the scan path is likely '
            . 'broken, which would make the contract sweep below silently pass.',
        );
    }

    public function testEveryResourceDtoIsAFlatImmutableScalarOnlyDto(): void
    {
        $diagnostics = [];

        foreach ($this->resourceDtoClasses() as $class) {
            foreach ($this->contractViolations($class) as $violation) {
                $diagnostics[] = \sprintf('- %s: %s', $class, $violation);
            }
        }

        $this->assertSame(
            [],
            $diagnostics,
            "Resource DTOs must be flat, immutable and scalar-only (pre-format non-scalars in the mapper):\n"
            . \implode("\n", $diagnostics),
        );
    }

    public function testContractRejectsANonFlatResource(): void
    {
        $violations = $this->contractViolations(NonFlatResourceStub::class);

        $this->assertNotSame(
            [],
            $violations,
            'The contract check must reject a DTO carrying a raw DateTimeImmutable — otherwise the '
            . 'production sweep is a vacuous pass.',
        );
        $this->assertStringContainsString('createdAt', \implode("\n", $violations));
    }

    /**
     * @return list<class-string>
     */
    private function resourceDtoClasses(): array
    {
        $root = ApiSourceFiles::root();
        $classes = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $relative = \substr($file->getPathname(), \strlen($root) + 1);

            if (!\str_contains('/' . $relative, self::RESOURCE_DIR_SEGMENT)) {
                continue;
            }

            $fqcn = 'Erpify\\' . \str_replace('/', '\\', \substr($relative, 0, -\strlen('.php')));

            if (\class_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        \sort($classes);

        return $classes;
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function contractViolations(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $violations = [];

        if (!$reflection->isFinal()) {
            $violations[] = 'must be declared final';
        }

        if (!$reflection->isReadonly()) {
            $violations[] = 'must be declared readonly';
        }

        if (!$reflection->hasMethod('__construct')) {
            $violations[] = 'must declare a constructor';
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if ('__construct' === $reflectionMethod->getName()) {
                continue;
            }

            $violations[] = \sprintf(
                'must expose no public method beyond the constructor (found %s())',
                $reflectionMethod->getName(),
            );
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            $type = $reflectionProperty->getType();

            if (!$this->isFlatScalarType($type)) {
                $violations[] = \sprintf(
                    'property $%s is %s; only flat scalar/null (string|int|float|bool) may reach the normalizer',
                    $reflectionProperty->getName(),
                    null === $type ? 'untyped' : (string) $type,
                );
            }
        }

        return $violations;
    }

    private function isFlatScalarType(?ReflectionType $type): bool
    {
        return $type instanceof ReflectionNamedType
            && $type->isBuiltin()
            && \in_array($type->getName(), self::FLAT_SCALAR_TYPES, true);
    }
}
