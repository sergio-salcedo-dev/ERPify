<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use ReflectionClass;
use Throwable;

/**
 * The classes under a source root whose constructor takes a given type, in any spelling of that type (nullable, union,
 * intersection — {@see ConstructorCollaboratorTypes} reads them all). The engine of the rules of the form "only these
 * classes may hold X", where the subject is a service whose very availability is the risk.
 *
 * A class is found by its path: `src/A/B.php` is `Erpify\A\B`, the PSR-4 mapping the whole tree follows, and a file
 * that
 * declares anything else or cannot be loaded is skipped rather than guessed at — which is why the gate that uses this
 * also asserts its own universe.
 *
 * @internal test support
 */
final class ConstructorDependents
{
    /**
     * @param class-string $service
     * @param string       $namespace the namespace `$root` maps to, `Erpify` for `api/src`
     *
     * @return list<class-string> sorted, each once
     */
    public static function of(string $service, ?string $root = null, string $namespace = 'Erpify'): array
    {
        $root ??= ApiSourceFiles::root();
        $dependents = [];

        foreach (self::classesUnder($root, $namespace) as $class) {
            if (\in_array($service, ConstructorCollaboratorTypes::of($class), true)) {
                $dependents[] = $class;
            }
        }

        \sort($dependents);

        return $dependents;
    }

    /**
     * How many classes the sweep looked at, so a gate can refuse a universe that quietly shrank.
     */
    public static function universeSize(?string $root = null): int
    {
        return \count(self::classesUnder($root ?? ApiSourceFiles::root(), 'Erpify'));
    }

    /**
     * @return list<class-string>
     */
    private static function classesUnder(string $root, string $namespace): array
    {
        $classes = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $relative = \substr($file->getPathname(), \strlen($root) + 1, -\strlen('.php'));
            $name = $namespace . '\\' . \str_replace('/', '\\', $relative);

            try {
                if (!\class_exists($name) && !\interface_exists($name) && !\trait_exists($name)) {
                    continue;
                }

                if ((new ReflectionClass($name))->getName() === $name) {
                    $classes[] = $name;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $classes;
    }
}
