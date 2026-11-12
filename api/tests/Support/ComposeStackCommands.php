<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The `command` each service runs once a stack of compose files is merged, the way `docker compose -f a -f b`
 * merges it.
 *
 * The rules were measured, not assumed, on Docker Compose v5.5.1 with
 * `docker compose -f compose.overlay-base.yaml -f compose.overlay-<case>.yaml config --format json` over the
 * fixtures in `tests/Unit/Gate/Fixture/ScheduleConsumption/`: the last file declaring `command` wins it whole
 * (an overlay's list replaces the base's and is never concatenated), `command: []` replaces it with an empty
 * one, `command: ~` removes the inherited command, and an overlay redefining a service without the key
 * inherits it. Presence is therefore tested with `array_key_exists` — `isset` reads an explicit null as
 * "not declared" and would hand the overlay the very command it removed.
 *
 * Only `command` is merged. A top-level `include:`, a service's `extends:` and the `profiles:` of a service that
 * declares a `command` anywhere in the stack are refused with a `RuntimeException`, and a `!reset`/`!override` tag
 * makes the YAML parser throw a `ParseException` — each a failure rather than a green. `profiles:` on a service
 * that declares no `command` is read: it cannot hide a consumer, and the Documents scanner (the image's own
 * entrypoint) is gated behind one in the dev overlay. `entrypoint:` and `deploy.replicas: 0` are still read past.
 *
 * @internal test support
 */
final class ComposeStackCommands
{
    /**
     * @throws RuntimeException on an empty stack, or on a file that declares no services — each would shrink
     *                          what is read into nothing, and nothing passes every assertion a gate makes
     *
     * @return array<string, mixed> the effective `command` per service that has one declared anywhere in the
     *                              stack, null where the last declaration removed it
     */
    public static function of(string ...$composeFiles): array
    {
        if ([] === $composeFiles) {
            throw new RuntimeException(
                'No compose file was given to read. An empty stack consumes nothing and reports nothing stale.',
            );
        }

        $commands = [];
        $profiled = [];

        foreach ($composeFiles as $composeFile) {
            foreach (self::servicesIn($composeFile) as $service => $definition) {
                self::refuseExtends($composeFile, (string) $service, $definition);

                if (\is_array($definition) && \array_key_exists('profiles', $definition)) {
                    $profiled[(string) $service] = $composeFile;
                }

                if (\is_array($definition) && \array_key_exists('command', $definition)) {
                    $commands[(string) $service] = $definition['command'];
                }
            }
        }

        self::refuseProfiledConsumers($profiled, $commands);

        return $commands;
    }

    /**
     * One service as the merged stack defines it: mappings merge key by key, `command` and `entrypoint` are replaced
     * by the last file declaring them, and every other list (networks, volumes, tmpfs, cap_add, ...) is the base's
     * followed by what the overlay adds. A service no file declares answers an empty map, so an assertion on it fails
     * rather than passing over nothing. Subject to the same refusals as {@see of()}.
     *
     * @throws RuntimeException
     *
     * @return array<array-key, mixed>
     */
    public static function serviceOf(string $service, string ...$composeFiles): array
    {
        self::of(...$composeFiles);

        $merged = [];

        foreach ($composeFiles as $composeFile) {
            $definition = self::servicesIn($composeFile)[$service] ?? null;

            if (\is_array($definition)) {
                $merged = self::mergeDefinitions($merged, $definition);
            }
        }

        return $merged;
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     *
     * @return array<array-key, mixed>
     */
    private static function mergeDefinitions(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            $inherited = $base[$key] ?? null;

            if (\is_array($value) && \is_array($inherited) && !\in_array($key, ['command', 'entrypoint'], true)) {
                $base[$key] = \array_is_list($value) && \array_is_list($inherited)
                    ? \array_values(\array_unique([...$inherited, ...$value], SORT_REGULAR))
                    : self::mergeDefinitions($inherited, $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @throws RuntimeException
     *
     * @return array<mixed>
     */
    private static function servicesIn(string $composeFile): array
    {
        $parsed = Yaml::parseFile($composeFile);

        if (!\is_array($parsed) || !\is_array($parsed['services'] ?? null)) {
            throw new RuntimeException(\sprintf('"%s" declares no services to read.', $composeFile));
        }

        if (\array_key_exists('include', $parsed)) {
            throw new RuntimeException(\sprintf(
                '"%s" declares a top-level `include:`, whose services this reader cannot see; resolve it before '
                . 'adding one, or an included consumer reads as consuming nothing.',
                $composeFile,
            ));
        }

        return $parsed['services'];
    }

    /**
     * `profiles:` keeps a service from starting by default; read past on a service that has a command, it counts a
     * consumer that never runs.
     *
     * @param array<string, string> $profiled service => the file that gave it `profiles:`
     * @param array<string, mixed>  $commands
     *
     * @throws RuntimeException
     */
    private static function refuseProfiledConsumers(array $profiled, array $commands): void
    {
        foreach ($profiled as $service => $composeFile) {
            if (\array_key_exists($service, $commands)) {
                throw new RuntimeException(\sprintf(
                    'Service "%s" in "%s" declares `profiles:`, which this reader does not model.',
                    $service,
                    $composeFile,
                ));
            }
        }
    }

    /**
     * `extends:` hides an inherited command; read past, it reports a consumer as consuming nothing. (`profiles:` is
     * refused after the merge, because only a service that has a command can be hidden by one.).
     *
     * @throws RuntimeException
     */
    private static function refuseExtends(string $composeFile, string $service, mixed $definition): void
    {
        if (\is_array($definition) && \array_key_exists('extends', $definition)) {
            throw new RuntimeException(\sprintf(
                'Service "%s" in "%s" declares `extends:`, which this reader does not model.',
                $service,
                $composeFile,
            ));
        }
    }
}
