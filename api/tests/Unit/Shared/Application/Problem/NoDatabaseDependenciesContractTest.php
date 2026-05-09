<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Problem;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Pins the no-database-on-the-error-path invariant: neither
 * {@see ExceptionResponder} nor {@see ProblemDetailsFactory} may declare a constructor
 * dependency on `Doctrine\DBAL\Connection`, `Doctrine\ORM\EntityManagerInterface`, any
 * subclass / sub-interface of those, or a Doctrine `ManagerRegistry`. A driver-side
 * connection failure (e.g. `Doctrine\DBAL\Exception\ConnectionLost`) thrown
 * mid-controller MUST still produce a conforming Problem Details 500 — the listener
 * cannot itself reach for the database while building that response.
 *
 * Direct-type-only by design: the reflection walk inspects each constructor parameter's
 * declared type, including each branch of a union type. Transitive deps (e.g. a service
 * that injects `Connection` into a helper of one of these classes) are out of scope for
 * this pin — the architecture rule is "the listener and factory reach for nothing
 * Doctrine-shaped at construction time", and that is a direct-type contract.
 *
 * @internal
 */
#[CoversNothing]
final class NoDatabaseDependenciesContractTest extends TestCase
{
    /**
     * Banned types. `Connection` covers DBAL; `EntityManagerInterface` covers ORM;
     * `ManagerRegistry` covers the umbrella registry that hands out either. Subclass /
     * sub-interface checks below catch concrete repository or per-tenant manager
     * wrappers that extend these contracts.
     *
     * @return list<class-string>
     */
    private function bannedTypes(): array
    {
        $banned = [];

        if (\interface_exists(Connection::class) || \class_exists(Connection::class)) {
            $banned[] = Connection::class;
        }

        if (\interface_exists(EntityManagerInterface::class)) {
            $banned[] = EntityManagerInterface::class;
        }

        if (\interface_exists(ManagerRegistry::class)) {
            $banned[] = ManagerRegistry::class;
        }

        return $banned;
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideConstructorDoesNotDependOnDoctrineConnectionOrEntityManagerCases')]
    public function testConstructorDoesNotDependOnDoctrineConnectionOrEntityManager(string $class): void
    {
        $reflectionClass = new ReflectionClass($class);
        $constructor = $reflectionClass->getConstructor();
        $this->assertInstanceOf(
            ReflectionMethod::class,
            $constructor,
            \sprintf('%s must declare a constructor for the dependency-shape pin.', $class),
        );

        $banned = $this->bannedTypes();
        $this->assertNotEmpty(
            $banned,
            'At least one Doctrine banned type must be resolvable in the test environment '
            . '(otherwise the pin is vacuous). Check that doctrine/dbal and doctrine/orm '
            . 'are installed via composer require-dev.',
        );

        foreach ($constructor->getParameters() as $reflectionParameter) {
            $this->assertParameterDoesNotDependOnBannedType($reflectionParameter, $banned, $class);
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideConstructorDoesNotDependOnDoctrineConnectionOrEntityManagerCases(): iterable
    {
        yield 'ExceptionResponder' => [ExceptionResponder::class];
        yield 'ProblemDetailsFactory' => [ProblemDetailsFactory::class];
    }

    /**
     * @param list<class-string> $banned
     */
    private function assertParameterDoesNotDependOnBannedType(
        ReflectionParameter $parameter,
        array $banned,
        string $class,
    ): void {
        $type = $parameter->getType();

        if (null === $type) {
            // An untyped constructor argument is itself a separate code-quality smell, but it
            // cannot resolve to a banned Doctrine type via the autowirer either way.
            return;
        }

        foreach ($this->resolveNamedTypes($type) as $reflectionNamedType) {
            $typeName = $reflectionNamedType->getName();

            if ($reflectionNamedType->isBuiltin()) {
                continue;
            }

            foreach ($banned as $bannedType) {
                $this->assertFalse(
                    $this->isOrExtends($typeName, $bannedType),
                    \sprintf(
                        '%s::__construct must not depend on %s (parameter $%s, type %s) — '
                        . 'the error path is required to hold zero database dependency.',
                        $class,
                        $bannedType,
                        $parameter->getName(),
                        $typeName,
                    ),
                );
            }
        }
    }

    /**
     * Walks union types so each branch is inspected. Non-union named types yield a
     * single-element list; intersection types are not exercised by either constructor today
     * but would be handled by a future overload of this helper.
     *
     * @return list<ReflectionNamedType>
     */
    private function resolveNamedTypes(ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type];
        }

        if ($type instanceof ReflectionUnionType) {
            $named = [];

            foreach ($type->getTypes() as $branch) {
                if ($branch instanceof ReflectionNamedType) {
                    $named[] = $branch;
                }
            }

            return $named;
        }

        return [];
    }

    /**
     * `is_a($candidate, $needle, allow_string: true)` returns `true` for either a class
     * extending $needle, an interface extending $needle, or a class implementing $needle.
     * That covers every "subclass or sub-interface" path this contract cares about.
     */
    private function isOrExtends(string $candidate, string $needle): bool
    {
        if ($candidate === $needle) {
            return true;
        }

        if (!\class_exists($candidate) && !\interface_exists($candidate)) {
            return false;
        }

        return \is_a($candidate, $needle, allow_string: true);
    }
}
