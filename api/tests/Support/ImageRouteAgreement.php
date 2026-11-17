<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Erpify\Shared\Http\Infrastructure\Upload\RawBodyRoute;
use Erpify\Shared\Images\Application\ImageAttachment;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The rule engine of `ImageWriteRouteAgreementGateTest`: what each route of the router's inventory carries that the
 * edge has to agree with, and the two ways a route and the Caddyfile's `@imageWrite` matcher can disagree.
 *
 * A route is described by four facts — its public path and methods (the committed manifest, which is the prod router)
 * and, read from its own source, whether it carries {@see RawBodyRoute::ATTRIBUTE} and whether its controller reaches
 * {@see ImageAttachment} through the application classes it takes as constructor arguments. Nothing here keys on a
 * route's name or on how its path ends.
 *
 * @phpstan-type RouteFacts array{
 *     path: string,
 *     methods: list<string>,
 *     carriesRawBody: bool,
 *     reachesImageAttachment: bool,
 * }
 *
 * @internal test support
 */
final class ImageRouteAgreement
{
    /** Constructor-argument hops from a controller to the attachment service: controller → use case → service. */
    private const int MAX_HOPS = 4;

    private const string ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private function __construct()
    {
    }

    /**
     * @return non-empty-string the path pattern of the `@imageWrite` matcher, as PCRE
     */
    public static function imageWritePattern(): string
    {
        $caddyfile = (string) \file_get_contents(\dirname(__DIR__, 2) . '/frankenphp/Caddyfile');
        $found = \preg_match('~\t\t@imageWrite \{\n((?:\t{3}[^\n]*\n)+)\t\t\}~', $caddyfile, $block);

        $matched = 1 === $found
            && 1 === \preg_match('~vars_regexp \{http\.request\.orig_uri\.path\} (\S+)~', $block[1], $pattern);

        if (!$matched) {
            throw new RuntimeException('The Caddyfile no longer declares @imageWrite with a path pattern.');
        }

        return '~' . $pattern[1] . '~';
    }

    /**
     * The disagreements, one sentence each: a `PUT` the matcher admits that carries no raw-body attribute, and a `PUT`
     * that carries it or reaches the attachment service that the matcher does not admit. A route without `PUT` is owed
     * nothing — the matcher names the method, and a `DELETE` has no body to bound or to spool.
     *
     * @param array<string, RouteFacts> $facts
     *
     * @return list<string>
     */
    public static function problems(array $facts, string $pattern): array
    {
        $problems = [];

        foreach ($facts as $name => $fact) {
            if (!\in_array('PUT', $fact['methods'], true)) {
                continue;
            }

            $public = \preg_replace('~\{[^/}]+\}~', self::ID, $fact['path']) ?? $fact['path'];
            $admitted = 1 === \preg_match($pattern, $public);
            $problem = self::problemOf($name, $fact, $admitted);

            if (null !== $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * Every manifest route with its facts, keyed by route name.
     *
     * @return array<string, RouteFacts>
     */
    public static function facts(): array
    {
        $manifest = \json_decode((string) \file_get_contents(\dirname(__DIR__, 2) . '/.route-manifest.json'), true);

        if (!\is_array($manifest)) {
            throw new RuntimeException('api/.route-manifest.json is not readable JSON.');
        }

        $declared = self::declaredRoutes();
        $facts = [];

        foreach ($manifest as $name => $entry) {
            $facts[(string) $name] = [
                'path' => Dig::string($entry, 'path'),
                'methods' => \array_values(\array_filter(Dig::array($entry, 'methods'), \is_string(...))),
                'carriesRawBody' => $declared[$name]['carriesRawBody'] ?? false,
                'reachesImageAttachment' => $declared[$name]['reachesImageAttachment'] ?? false,
            ];
        }

        return $facts;
    }

    /**
     * @param RouteFacts $fact
     */
    private static function problemOf(string $name, array $fact, bool $admitted): ?string
    {
        if ($admitted && !$fact['carriesRawBody']) {
            return \sprintf(
                'The @imageWrite matcher admits the PUT route %s (%s), which carries no %s: its plaintext spool '
                . 'would never be destroyed.',
                $name,
                $fact['path'],
                RawBodyRoute::ATTRIBUTE,
            );
        }

        if ($admitted || (!$fact['carriesRawBody'] && !$fact['reachesImageAttachment'])) {
            return null;
        }

        return \sprintf(
            'The @imageWrite matcher does not admit the PUT route %s (%s), which %s: the edge would cut its body at '
            . '1MB and the application would store a truncated image.',
            $name,
            $fact['path'],
            $fact['carriesRawBody'] ? 'carries ' . RawBodyRoute::ATTRIBUTE : 'reaches ImageAttachment',
        );
    }

    /**
     * @return array<string, array{carriesRawBody: bool, reachesImageAttachment: bool}> by route name
     */
    private static function declaredRoutes(): array
    {
        $declared = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $class = self::controllerClassOf($file->getPathname());

            if (null === $class) {
                continue;
            }

            $reaches = self::reaches($class, ImageAttachment::class, self::MAX_HOPS, []);

            foreach (self::routesOf($class) as $route) {
                if (null !== $route->name) {
                    $declared[$route->name] = [
                        'carriesRawBody' => true === ($route->defaults[RawBodyRoute::ATTRIBUTE] ?? null),
                        'reachesImageAttachment' => $reaches,
                    ];
                }
            }
        }

        return $declared;
    }

    /**
     * @return class-string|null the class of a file that declares a route, or null when it declares none
     */
    private static function controllerClassOf(string $path): ?string
    {
        if (!\str_contains((string) \file_get_contents($path), '#[Route(')) {
            return null;
        }

        $relative = \substr(\str_replace('\\', '/', $path), \strlen(ApiSourceFiles::root()) + 1, -4);
        $class = 'Erpify\\' . \str_replace('/', '\\', $relative);

        return \class_exists($class) ? $class : null;
    }

    /**
     * @param class-string $class
     *
     * @return list<Route>
     */
    private static function routesOf(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(Route::class);

        foreach ($reflection->getMethods() as $method) {
            \array_push($attributes, ...$method->getAttributes(Route::class));
        }

        return \array_map(static fn (ReflectionAttribute $attribute): Route => $attribute->newInstance(), $attributes);
    }

    /**
     * Whether `$class` takes `$target` as a constructor argument, directly or through the application classes it takes.
     *
     * @param list<string> $seen
     */
    private static function reaches(string $class, string $target, int $hops, array $seen): bool
    {
        if ($hops < 0 || \in_array($class, $seen, true) || !\class_exists($class)) {
            return false;
        }

        return \array_any(
            self::applicationDependencies($class),
            static fn (string $dependency): bool => $dependency === $target
                || self::reaches($dependency, $target, $hops - 1, [...$seen, $class]),
        );
    }

    /**
     * @param class-string $class
     *
     * @return list<string> the classes of the application a constructor takes by type
     */
    private static function applicationDependencies(string $class): array
    {
        $dependencies = [];

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            $named = $type instanceof ReflectionNamedType && !$type->isBuiltin();

            if ($named && \str_starts_with($type->getName(), 'Erpify\\')) {
                $dependencies[] = $type->getName();
            }
        }

        return $dependencies;
    }
}
