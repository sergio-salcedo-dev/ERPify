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
 * inherits it. Presence is therefore
 * tested with `array_key_exists` — `isset` reads an explicit null as "not declared" and would hand the
 * overlay the very command it removed.
 *
 * Only `command` is merged. `extends`, `include` and the `!reset`/`!override` tags are not modelled; a custom
 * tag makes the YAML parser throw a `ParseException`, which is a failure rather than a green.
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

        foreach ($composeFiles as $composeFile) {
            foreach (self::servicesIn($composeFile) as $service => $definition) {
                if (\is_array($definition) && \array_key_exists('command', $definition)) {
                    $commands[(string) $service] = $definition['command'];
                }
            }
        }

        return $commands;
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

        return $parsed['services'];
    }
}
