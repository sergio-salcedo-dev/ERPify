<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Problem;

use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Story 3.5 (FR41 / NFR16) — pins the worker-mode safety invariant: neither
 * {@see ExceptionResponder} nor {@see ProblemDetailsFactory} may carry a `private`
 * non-readonly mutable property. Both classes are `final readonly` today, so every
 * promoted constructor argument is auto-readonly and the invariant is structurally
 * satisfied. This test makes that invariant load-bearing — a future PR adding a
 * mutable per-request cache (e.g. `private array $cache = [];`) on either class fails
 * CI before it can corrupt FrankenPHP worker-mode reuse across requests.
 *
 * Cross-cutting on purpose. Colocated with `ProblemDetailsFactoryTest` (the factory
 * is the larger surface area) but covers `ExceptionResponder` too — splitting into
 * two files would either duplicate the data provider or create an artificial seam
 * across packages for what is one architectural rule.
 *
 * @internal
 */
#[CoversNothing]
final class StatelessPropertiesContractTest extends TestCase
{
    /**
     * @param class-string $class
     */
    #[DataProvider('provideStatelessClasses')]
    public function testClassIsFinalAndDeclaredReadonly(string $class): void
    {
        $reflectionClass = new ReflectionClass($class);

        $this->assertTrue(
            $reflectionClass->isFinal(),
            \sprintf('%s must be final to lock the worker-mode safety invariant.', $class),
        );
        $this->assertTrue(
            $reflectionClass->isReadOnly(),
            \sprintf(
                '%s must be declared `readonly` (PHP 8.2+) so every property is auto-readonly. '
                . 'A mutable per-request property would break FrankenPHP worker-mode reuse (NFR16).',
                $class,
            ),
        );
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideStatelessClasses')]
    public function testEveryDeclaredPropertyIsReadonly(string $class): void
    {
        $reflectionClass = new ReflectionClass($class);
        $properties = $reflectionClass->getProperties();

        // The declared-property surface (constants are out of scope — they are class-level
        // and immutable). On a `final readonly` class, every property is auto-readonly; the
        // explicit per-property check below pins the invariant against future drift such as
        // dropping the class-level `readonly` keyword.
        if ([] === $properties) {
            // ExceptionResponder / ProblemDetailsFactory both promote ctor args to declared
            // properties, so an empty array would itself indicate a regression.
            $this->fail(\sprintf(
                '%s declared zero properties — expected promoted readonly ctor args.',
                $class,
            ));
        }

        foreach ($properties as $property) {
            $this->assertReadonlyDeclaredProperty($property, $class);
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideStatelessClasses(): iterable
    {
        yield 'ExceptionResponder' => [ExceptionResponder::class];
        yield 'ProblemDetailsFactory' => [ProblemDetailsFactory::class];
    }

    /**
     * Story 3.5 (FR41 / NFR16) — assert a single declared property is readonly. Static caches
     * are explicitly disallowed: a `static` property on a stateless service is the canonical
     * worker-mode-poisoning vector (lives across requests, never reset by `kernel.reset`).
     */
    private function assertReadonlyDeclaredProperty(ReflectionProperty $property, string $class): void
    {
        $name = $property->getName();

        $this->assertFalse(
            $property->isStatic(),
            \sprintf(
                '%s::$%s must not be static — a static property survives `kernel.reset` and '
                . 'leaks state across worker-mode requests (NFR16).',
                $class,
                $name,
            ),
        );

        $this->assertTrue(
            $property->isReadOnly(),
            \sprintf(
                '%s::$%s must be readonly. A mutable property breaks the worker-mode safety '
                . 'invariant (FR41 / NFR16) — `kernel.reset` will not reset it across requests.',
                $class,
                $name,
            ),
        );
    }
}
