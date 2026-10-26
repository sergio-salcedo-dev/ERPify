<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The files the acceptance lane is allowed to credit with coverage: the system's ENTRY POINTS — every class
 * Symfony routes an HTTP request to (`#[Route]` on the class or on a method) and every console command
 * (`#[AsCommand]`). `api/behat.dist.php` feeds this list to the coverage collector's filter.
 *
 * Why only these. A scenario's oracle is the observable contract of the request or command it drives — the
 * response, the exit code, the side effect the use case owns — and that contract is the entry point's own
 * responsibility. Every other class a scenario traverses (a listener, a voter, a serializer, a repository)
 * is EXECUTED by it, not verified by it, and this project credits a class only where a test verifies its
 * behaviour. SonarCloud merges coverage reports per line by logical OR, so a line Behat reports would count
 * as covered whatever PHPUnit's attributed report says: the filter is the only place that criterion can
 * hold for this lane.
 *
 * Derived by REFLECTION over `src/`, never by folder or file name: `Infrastructure/Http/` holds controllers
 * in some contexts and listeners in others, and `*Controller.php` is a naming habit nothing enforces. An
 * attribute is what makes Symfony treat a class as an entry point, so it is what decides here too.
 *
 * @internal test support
 */
final class AcceptanceEntryPoints
{
    /**
     * @return list<string> absolute paths, sorted
     */
    public static function files(?string $srcRoot = null): array
    {
        $srcRoot = \realpath($srcRoot ?? ApiSourceFiles::root());

        if (false === $srcRoot) {
            return [];
        }

        $files = [];

        foreach (ApiSourceFiles::phpFiles($srcRoot) as $file) {
            $path = (string) $file->getRealPath();
            $class = self::classOf($srcRoot, $path);

            if (null !== $class && self::isEntryPoint($class)) {
                $files[] = $path;
            }
        }

        \sort($files);

        return $files;
    }

    /**
     * @param class-string $class
     */
    public static function isEntryPoint(string $class): bool
    {
        $reflection = new ReflectionClass($class);

        if ([] !== $reflection->getAttributes(AsCommand::class)) {
            return true;
        }

        if ([] !== $reflection->getAttributes(Route::class)) {
            return true;
        }

        return \array_any(
            $reflection->getMethods(),
            static fn (ReflectionMethod $method): bool => [] !== $method->getAttributes(Route::class),
        );
    }

    /**
     * PSR-4: `Erpify\` maps to `src/`. A file declaring no class of its own name (an interface, a trait, an
     * enum — or a class outside the mapping) is not an entry point and yields null.
     *
     * @return class-string|null
     */
    private static function classOf(string $srcRoot, string $path): ?string
    {
        $relative = \substr($path, \strlen($srcRoot . '/'), -\strlen('.php'));
        $class = 'Erpify\\' . \str_replace('/', '\\', $relative);

        return \class_exists($class) ? $class : null;
    }
}
