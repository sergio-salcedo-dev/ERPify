<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * Derivation rules behind the schedule-consumption gate, kept out of the assertions so
 * {@see \Erpify\Tests\Unit\Shared\Architecture\ScheduleConsumptionRulesGateTest} can falsify them against
 * fixtures while the gate itself runs against the real tree.
 *
 * @internal test support
 */
final class ScheduleConsumption
{
    /**
     * The compose files a schedule's transport has to be named in. Both, not either: the dev stack folds the
     * scheduler transports into `messenger_worker` while prod isolates them on a single-replica
     * `scheduler_worker`, so a transport present in one file and missing from the other ships a schedule that
     * is alive in exactly one environment — and the one it is usually missing from is prod.
     */
    public const array COMPOSE_FILES = ['compose.yaml', 'compose.prod.yaml'];

    /**
     * Every schedule name declared by an `#[AsSchedule('…')]` attribute under `api/src`.
     *
     * Read as text rather than by reflection on purpose: reflection would need the container's compiled
     * schedule registry, which is exactly the thing a dead transport does not disturb — the attribute is
     * present and registered either way. The text sweep sees what a developer wrote, which is the claim
     * being checked.
     *
     * @return list<string> sorted, deduplicated
     */
    public static function declaredScheduleNames(?string $sourceRoot = null): array
    {
        $names = [];

        foreach (ApiSourceFiles::phpFiles($sourceRoot) as $file) {
            $contents = \file_get_contents($file->getPathname());

            if (false === $contents) {
                continue;
            }

            \preg_match_all("/#\\[AsSchedule\\(\\s*'([^']+)'/", $contents, $matches);
            $names = [...$names, ...$matches[1]];
        }

        $names = \array_values(\array_unique($names));
        \sort($names);

        return $names;
    }

    /**
     * The transport Symfony's `AddScheduleMessengerPass` creates for a schedule name. It is never declared in
     * `config/packages/messenger.yaml`, which is why nothing there can be read as evidence of consumption.
     */
    public static function transportOf(string $scheduleName): string
    {
        return 'scheduler_' . $scheduleName;
    }

    /**
     * Every transport named as an argument of a `messenger:consume` command in one compose file.
     *
     * Arguments only — the scan stops at the first `--option`, so a transport that appears somewhere else in
     * the file (a comment, an environment variable, another service's name) is not mistaken for a consumed
     * one. That is the difference between checking wiring and grepping for a word.
     *
     * @return list<string>
     */
    public static function consumedTransportsIn(string $composeFile): array
    {
        $contents = \file_get_contents($composeFile);

        if (false === $contents) {
            throw new RuntimeException(\sprintf('Could not read the compose file "%s".', $composeFile));
        }

        $consumed = [];

        // One entry per `messenger:consume` command line, each captured up to the end of its YAML sequence.
        \preg_match_all('/"messenger:consume"((?:\s*,\s*"[^"]+")+)/', $contents, $commands);

        foreach ($commands[1] as $arguments) {
            \preg_match_all('/"([^"]+)"/', $arguments, $tokens);

            foreach ($tokens[1] as $token) {
                if (\str_starts_with($token, '-')) {
                    break;
                }

                $consumed[] = $token;
            }
        }

        return \array_values(\array_unique($consumed));
    }

    /**
     * Schedule transports named in a compose file that no `#[AsSchedule]` produces — the stale half of the
     * comparison. A consume command keeps a removed schedule's transport alive as a name Messenger cannot
     * resolve, and the worker then fails to boot rather than degrading, so this direction is a deploy
     * outage waiting for the next restart.
     *
     * @param list<string> $declaredScheduleNames
     *
     * @return list<string>
     */
    public static function unbackedSchedulerTransportsIn(string $composeFile, array $declaredScheduleNames): array
    {
        $expected = \array_map(self::transportOf(...), $declaredScheduleNames);

        return \array_values(\array_filter(
            self::consumedTransportsIn($composeFile),
            static fn (string $transport): bool => \str_starts_with($transport, 'scheduler_')
                && !\in_array($transport, $expected, true),
        ));
    }
}
