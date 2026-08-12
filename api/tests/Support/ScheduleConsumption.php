<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

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
     * The service that is allowed to consume scheduler transports, per compose file.
     *
     * The pairing is the invariant, not the presence: Symfony Scheduler derives every tick from an in-process
     * clock, so a scheduler transport consumed by the horizontally scalable `messenger_worker` pool in prod
     * emits one tick per replica. `compose.prod.yaml` says as much beside `replicas: 1`, and until this map
     * existed nothing checked it — a transport moved onto the scaled pool satisfied a file-wide scan exactly
     * as well as the correct wiring does.
     */
    public const array SCHEDULER_CONSUMER = [
        'compose.yaml' => 'messenger_worker',
        'compose.prod.yaml' => 'scheduler_worker',
    ];

    /**
     * The name Symfony gives a schedule whose attribute carries no argument.
     */
    private const string DEFAULT_SCHEDULE_NAME = 'default';

    private const string CONSUME_COMMAND = 'messenger:consume';

    /**
     * Every schedule name declared by an `#[AsSchedule]` attribute under `api/src`.
     *
     * Read as text rather than by reflection on purpose: reflection would need the container's compiled
     * schedule registry, which is exactly the thing a dead transport does not disturb — the attribute is
     * present and registered either way. The text sweep sees what a developer wrote, which is the claim
     * being checked.
     *
     * Every spelling Symfony accepts, not just the one the tree happens to use: a bare `#[AsSchedule]`
     * (which the attribute's own default turns into `scheduler_default`), a double-quoted or named
     * argument, and a fully-qualified attribute name. A sweep that saw only single-quoted positional
     * arguments would let the other four ship an unconsumed transport with this gate green, which is the
     * failure it exists to refuse.
     *
     * @throws RuntimeException on a file it cannot read or an attribute argument it cannot understand —
     *                          both would otherwise shrink the universe silently, and a smaller universe
     *                          passes every assertion below
     *
     * @return list<string> sorted, deduplicated
     */
    public static function declaredScheduleNames(?string $sourceRoot = null): array
    {
        $names = [];

        foreach (ApiSourceFiles::phpFiles($sourceRoot) as $file) {
            $contents = \file_get_contents($file->getPathname());

            if (false === $contents) {
                throw new RuntimeException(\sprintf(
                    'Could not read "%s". A source file skipped here drops its schedules out of both '
                    . 'directions of this gate without failing anything.',
                    $file->getPathname(),
                ));
            }

            $names = [...$names, ...self::scheduleNamesIn($contents, $file->getPathname())];
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
     * The transports each service's `messenger:consume` command names, keyed by service.
     *
     * Parsed as YAML rather than scanned as bytes, and that is the whole difference between checking wiring
     * and grepping for a word. A byte scan reads a commented-out command as live wiring, cannot see a
     * command written as a plain string or a block sequence, and has no idea which service a receiver
     * belongs to — three ways for a schedule to ship dead behind a green gate.
     *
     * @throws RuntimeException
     *
     * @return array<string, list<string>>
     */
    public static function consumedTransportsByServiceIn(string $composeFile): array
    {
        $parsed = Yaml::parseFile($composeFile);

        if (!\is_array($parsed) || !\is_array($parsed['services'] ?? null)) {
            throw new RuntimeException(\sprintf('"%s" declares no services to read.', $composeFile));
        }

        $byService = [];

        foreach ($parsed['services'] as $service => $definition) {
            if (!\is_array($definition)) {
                continue;
            }

            if (!isset($definition['command'])) {
                continue;
            }

            $receivers = self::receiversIn(self::tokensOf($definition['command']));

            if ([] !== $receivers) {
                $byService[(string) $service] = $receivers;
            }
        }

        return $byService;
    }

    /**
     * Every transport consumed anywhere in one compose file, regardless of which service consumes it.
     *
     * The right shape for the stale direction — an argument no schedule backs stops the worker booting
     * whichever service carries it — and the wrong shape for the forward one, which has to care about the
     * service. {@see consumedTransportsByServiceIn()} is what that direction uses.
     *
     * @throws RuntimeException
     *
     * @return list<string>
     */
    public static function consumedTransportsIn(string $composeFile): array
    {
        $consumed = \array_merge(...\array_values(self::consumedTransportsByServiceIn($composeFile)));

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
     * @throws RuntimeException
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

    /**
     * @throws RuntimeException
     *
     * @return list<string>
     */
    private static function scheduleNamesIn(string $contents, string $path): array
    {
        $pattern = '/#\[\s*(?:\\\?[A-Za-z_]\w*\\\)*AsSchedule(?<arguments>\([^()]*\))?/';

        // Comments first, or the sweep reads prose as a declaration: a command documenting that it is NOT
        // scheduled — and what scheduling it would take — makes this gate demand a transport nobody wrote.
        // The bare spelling turns that from a false alarm into a false negative, since it resolves to
        // `default`: a sentence can supply a name that a real schedule added later then collides with.
        $contents = PhpSource::withoutComments($contents);

        if (0 === \preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $names = [];

        foreach ($matches as $match) {
            $arguments = $match['arguments'] ?? '';

            if ('' === $arguments || '()' === \preg_replace('/\s+/', '', $arguments)) {
                $names[] = self::DEFAULT_SCHEDULE_NAME;

                continue;
            }

            if (1 === \preg_match('/(?:name\s*:\s*)?[\'"]([^\'"]+)[\'"]/', $arguments, $named)) {
                $names[] = $named[1];

                continue;
            }

            throw new RuntimeException(\sprintf(
                'Could not read the schedule name from `#[AsSchedule%s]` in "%s". Guessing would hide a '
                . 'transport from both directions of this gate; spell the name as a literal instead.',
                $arguments,
                $path,
            ));
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function tokensOf(mixed $command): array
    {
        if (\is_string($command)) {
            return \preg_split('/\s+/', \trim($command), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (!\is_array($command)) {
            return [];
        }

        return \array_values(\array_map(
            static fn (mixed $token): string => \is_scalar($token) ? (string) $token : '',
            $command,
        ));
    }

    /**
     * The receivers a `messenger:consume` token list names.
     *
     * Every non-option token after the command, not everything up to the first option: `ArgvInput` accepts
     * arguments and options in any order and `receivers` is variadic, so `messenger:consume async
     * --time-limit=3600 scheduler_x` really does consume `scheduler_x`. Reading it the other way made a
     * correct wiring look unconsumed and — the direction that actually costs something — hid a leftover
     * argument from the stale check, whose failure mode is a worker that will not boot on the next restart.
     *
     * A value passed as a separate token (`--time-limit 3600`) is counted as a receiver by this rule. That
     * is harmless in both directions: the forward one only ever gains names, and the stale one keeps only
     * those prefixed `scheduler_`.
     *
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private static function receiversIn(array $tokens): array
    {
        $commandIndex = \array_search(self::CONSUME_COMMAND, $tokens, true);

        if (!\is_int($commandIndex)) {
            return [];
        }

        $receivers = [];

        foreach (\array_slice($tokens, $commandIndex + 1) as $token) {
            if ('' === $token) {
                continue;
            }

            if (\str_starts_with($token, '-')) {
                continue;
            }

            $receivers[] = $token;
        }

        return $receivers;
    }
}
