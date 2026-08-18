<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Whether a PREFIX exemption's subtree is actually kept out of production.
 *
 * This is the half that makes such a classification falsifiable. "Inert in production" is an assertion, and
 * until something checks it, it holds only while an unrelated file keeps a `when@test:` wrapper nobody is
 * watching — the same shape as a `#[When(env: 'dev')]` guard, which needed its own gate for exactly this
 * reason.
 *
 * @internal test support
 */
final class PublicAccessPrefixBoundary
{
    /**
     * Routes under `$pattern` that the named file does not bound.
     *
     * @param array<string, mixed> $routeConfigs file path => parsed YAML
     *
     * @return list<string>
     */
    public static function unboundedRoutes(string $pattern, string $boundedBy, array $routeConfigs): array
    {
        $prefix = \ltrim($pattern, '^');
        $violations = [];

        foreach ($routeConfigs as $file => $config) {
            if (!\is_array($config)) {
                continue;
            }

            $violations = [
                ...$violations,
                ...self::escapedRoutes($config, $prefix, $pattern, $file, $boundedBy),
                ...self::overlappingResources($config, $prefix, $file),
            ];
        }

        return $violations;
    }

    /**
     * PHP routing files are imported exactly like the YAML ones and cannot be parsed as data, so the prefix
     * is matched against their SOURCE. That is coarse on purpose: a mention is reported whether or not the
     * route is guarded, because the alternative — ignoring a file format Symfony loads — is how a route live
     * in the production router sat under an anonymous prefix with this gate green.
     *
     * @param array<string, string> $phpSources file path => file contents
     *
     * @return list<string>
     */
    public static function phpRoutingMentions(string $pattern, array $phpSources): array
    {
        $prefix = \ltrim($pattern, '^');
        $violations = [];

        foreach ($phpSources as $file => $source) {
            if (\str_contains($source, $prefix)) {
                $violations[] = \sprintf(
                    'PHP routing file %s mentions `%s`. This gate cannot read PHP routing as data, so it '
                    . 'cannot establish that such a route is bounded — declare the route in the YAML file '
                    . 'the registry names, or the `%s` exemption is unverifiable.',
                    $file,
                    $prefix,
                    $pattern,
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return list<string>
     */
    private static function escapedRoutes(
        array $config,
        string $prefix,
        string $pattern,
        string $file,
        string $boundedBy,
    ): array {
        $violations = [];

        foreach (self::declaredPaths($config, false) as [$path, $underWhenTest]) {
            if (!\str_starts_with($path, $prefix) || ($file === $boundedBy && $underWhenTest)) {
                continue;
            }

            $violations[] = \sprintf(
                'Route "%s" is declared in %s%s, so the `%s` exemption is not inert in production. '
                . 'The registry names %s as what bounds it.',
                $path,
                $file,
                $underWhenTest ? ' under when@test' : ' outside any when@test block',
                $pattern,
                $boundedBy,
            );
        }

        return $violations;
    }

    /**
     * Every `path` the config declares, paired with whether a `when@test` block encloses it.
     *
     * @param array<array-key, mixed> $node
     *
     * @return list<array{string, bool}>
     */
    private static function declaredPaths(array $node, bool $underWhenTest): array
    {
        $paths = [];

        foreach ($node as $key => $value) {
            if ('path' === $key && \is_string($value)) {
                $paths[] = [$value, $underWhenTest];

                continue;
            }

            if (\is_array($value)) {
                $enclosed = $underWhenTest || (\is_string($key) && \str_starts_with($key, 'when@test'));
                $paths = [...$paths, ...self::declaredPaths($value, $enclosed)];
            }
        }

        return $paths;
    }

    /**
     * Attribute-routing resources whose `prefix` could put a route under `$prefix`. Two prefixes overlap
     * when either is a prefix of the other — an absent one is the empty string, so it overlaps everything,
     * which is precisely the shape that would slip a `/api/test/` route into production.
     *
     * The walk RECURSES: a resource nested under `when@prod:` loads in production exactly like a top-level
     * one, and a sweep of the top level alone reports it as absent.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list<string>
     */
    private static function overlappingResources(array $config, string $prefix, string $file): array
    {
        $violations = [];

        foreach ($config as $name => $definition) {
            if (!\is_array($definition)) {
                continue;
            }

            if ('attribute' !== ($definition['type'] ?? null)) {
                $violations = [...$violations, ...self::overlappingResources($definition, $prefix, $file)];

                continue;
            }

            $resourcePrefix = \is_string($definition['prefix'] ?? null) ? $definition['prefix'] : '';

            if (\str_starts_with($prefix, $resourcePrefix) || \str_starts_with($resourcePrefix, $prefix)) {
                $violations[] = \sprintf(
                    'Attribute-routing resource "%s" in %s can register a route under `%s`, which nothing '
                    . 'bounds. Give it a `prefix` that cannot overlap the exemption.',
                    (string) $name,
                    $file,
                    $prefix,
                );
            }
        }

        return $violations;
    }
}
