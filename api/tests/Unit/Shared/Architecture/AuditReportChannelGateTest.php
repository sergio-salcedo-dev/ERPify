<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Gate over the one property that decides whether a swallowed audit write is reported to anyone: the CHANNEL
 * its report is logged on.
 *
 * A class that catches a failed audit projection and logs it has done the honest thing and, on the default
 * channel, still produced nothing. `monolog.yaml` routes the default channel through `fingers_crossed` with
 * `action_level: error`, which buffers every record of a request and DISCARDS the buffer unless something
 * raises it — and these reports live on paths that answer 2xx or 401 by design, because swallowing is the
 * point. So the line is written, the test passes, and production never sees it. `observability` is the
 * always-on stream this repository built for exactly that shape, excluded from the buffered handler by name.
 *
 * The rule is therefore: **if you catch a failed audit write and report it, report it on `observability`**.
 * This reads the SOURCE, which is what an author writes and a reviewer checks, and it is the cheap half of
 * the answer: it holds every reporter to the channel without booting anything. It is not the whole answer,
 * because the binding it reads is a position in a file — see the blind spots below, and the arrival test
 * named there, which is the half that reads what Monolog actually wrote.
 *
 * **What a green does not prove**, and the first item is the one that matters most:
 *
 * - **That the binding is in effect.** `FileLoader::registerClasses()` overwrites definitions
 *   unconditionally, so an explicit block moved ABOVE the `Erpify\` prototype in `services.yaml` silently
 *   reverts the class to the autowired channel — and this gate, which reads the file as a map, cannot see
 *   order. {@see \Erpify\Tests\Functional\Iam\Identity\LockoutAuditWriteFailureArrivalTest} is the
 *   witness for that, by reading the bytes Monolog actually wrote.
 * - That `observability` stays always-on ({@see ObservabilityChannelGateTest}), that the sink is read by
 *   anyone, or that the report ever fired.
 * - The attribute branch is a byte scan, so a commented-out attribute or a docblock quoting it satisfies it —
 *   unlike the `services.yaml` branch, which is parsed. The asymmetry is knowingly left: the one class using
 *   the attribute is covered by its own arrival test.
 * - It reads `config/services.yaml` only. A `$logger` override living in an env-specific file
 *   (`config/services_test.yaml` already demonstrates the shape) is invisible to it.
 * - It matches short class names, so a same-named class in another namespace would satisfy the gate for a
 *   different one.
 * - It only sees classes that inject a logger, mention `catch (Throwable` and carry one of the literal
 *   phrases — a reporter worded differently escapes, which is why the population is pinned below.
 *
 * @internal
 */
#[CoversNothing]
final class AuditReportChannelGateTest extends TestCase
{
    private const string OBSERVABILITY_SERVICE = 'monolog.logger.observability';

    /**
     * The attribute spelling, available only to `Infrastructure`: deptrac refuses `Application` a dependency
     * on the DI container's attributes, so the two use cases are bound in `services.yaml` instead. Both
     * spellings satisfy this gate, because both produce the same wiring — what it refuses is neither.
     */
    private const string OBSERVABILITY_AUTOWIRE = "#[Autowire(service: 'monolog.logger.observability')]";

    private const string SERVICES_CONFIG = __DIR__ . '/../../../../config/services.yaml';

    /**
     * The classes that swallow a failed audit write and report it TO A LOGGER.
     *
     * The qualifier is the classification, not a hedge. `EraseActorAuditTrailCommand` also swallows a failed
     * `security` write — the GDPR erasure self-audit — and reports it through `SymfonyStyle::error()` plus a
     * non-zero exit code. That is a defensible surface for a CLI whose operator is present and reading the
     * output, and it is deliberately outside this gate: it injects no `LoggerInterface`, so there is no
     * channel to hold it to. Recorded here rather than left to a reader's grep, because "the classes that
     * swallow a failed audit write" would be false as a description of the list below.
     *
     * Pinned rather than only discovered, for the reason every sweep in this directory is: a pattern that
     * stops matching turns the gate into an empty loop that passes. The discovery below must find exactly
     * these, so a new reporter fails the count until it is classified here — and classifying it is the
     * moment to notice it needs the channel.
     */
    private const array REPORTERS = [
        'RecordLockoutAuditBestEffort.php',
        'RecordRecoveryThrottleAuditBestEffort.php',
        'SymfonyAuditLogger.php',
    ];

    /**
     * The phrases these reports use for the thing being reported. Matching on the MESSAGE rather than on a
     * class-name suffix is deliberate: `*BestEffort` also names classes that swallow a failed EMAIL, whose
     * channel is a separate decision this gate must not pre-empt.
     */
    private const array REPORTED_FAILURES = ['audit entry', 'audit projection skipped'];

    #[Test]
    public function everyReporterOfASwallowedAuditWriteLogsOnTheAlwaysOnChannel(): void
    {
        $offenders = [];

        $bound = $this->classesBoundInServicesConfig();

        foreach ($this->reporters() as $file => $contents) {
            if (\str_contains($contents, self::OBSERVABILITY_AUTOWIRE)) {
                continue;
            }

            if (\in_array(\basename($file, '.php'), $bound, true)) {
                continue;
            }

            $offenders[] = $file;
        }

        $this->assertSame([], $offenders, \sprintf(
            "These classes report a swallowed audit write on the DEFAULT channel:\n  %s\n"
            . 'In prod that channel is a `fingers_crossed` handler which discards the buffer unless the '
            . 'response is a 5xx — and these paths answer 2xx or 401 by design, because swallowing is the '
            . 'point. Bind `$logger` to `@%s` in services.yaml — which is what an `Application` class must '
            . 'do, since deptrac refuses that layer the container attributes — or add %s at the parameter '
            . 'when the class lives in `Infrastructure`.',
            \implode("\n  ", $offenders),
            self::OBSERVABILITY_SERVICE,
            self::OBSERVABILITY_AUTOWIRE,
        ));
    }

    #[Test]
    public function theSweepStillFindsEveryClassItIsMeantToHold(): void
    {
        // Without this the gate is vacuous in the direction that matters: a renamed class or a reworded
        // message empties the sweep, and an empty sweep satisfies the assertion above perfectly.
        $found = \array_map(\basename(...), \array_keys($this->reporters()));
        \sort($found);
        $expected = self::REPORTERS;
        \sort($expected);

        $this->assertSame($expected, $found, \sprintf(
            'The sweep no longer matches the pinned population. Either a reporter was added (classify it '
            . 'here, and give it %s) or one was renamed or reworded out of reach of this gate.',
            self::OBSERVABILITY_AUTOWIRE,
        ));
    }

    /**
     * @throws RuntimeException
     *
     * @return array<string, string> path => contents
     */
    private function reporters(): array
    {
        $reporters = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $contents = \file_get_contents($file->getPathname());

            if (false === $contents) {
                throw new RuntimeException(\sprintf(
                    'Could not read "%s". A file skipped here leaves its reporter out of the sweep without '
                    . 'failing anything.',
                    $file->getPathname(),
                ));
            }

            if (!$this->reportsASwallowedAuditWrite($contents)) {
                continue;
            }

            $reporters[$file->getPathname()] = $contents;
        }

        return $reporters;
    }

    /**
     * Short class names whose `$logger` argument `services.yaml` binds to the observability channel.
     *
     * Parsed as YAML rather than grepped: a commented-out binding reads as live wiring to a byte scan, which
     * is the failure this gate exists to refuse one level up.
     *
     * @throws RuntimeException
     *
     * @return list<string>
     */
    private function classesBoundInServicesConfig(): array
    {
        // `PARSE_CUSTOM_TAGS`, because the file legitimately carries `!tagged_iterator`; without it the
        // parser throws and this gate would fail for a reason that has nothing to do with what it checks.
        $parsed = Yaml::parseFile(self::SERVICES_CONFIG, Yaml::PARSE_CUSTOM_TAGS);

        if (!\is_array($parsed) || !\is_array($parsed['services'] ?? null)) {
            throw new RuntimeException('services.yaml declares no services to read.');
        }

        $bound = [];

        foreach ($parsed['services'] as $id => $definition) {
            if (!\is_array($definition)) {
                continue;
            }

            if (!\is_array($definition['arguments'] ?? null)) {
                continue;
            }

            if (('@' . self::OBSERVABILITY_SERVICE) !== ($definition['arguments']['$logger'] ?? null)) {
                continue;
            }

            $id = (string) $id;
            $bound[] = \substr($id, (int) \strrpos($id, '\\') + 1);
        }

        return $bound;
    }

    private function reportsASwallowedAuditWrite(string $contents): bool
    {
        if (!\str_contains($contents, 'LoggerInterface')) {
            return false;
        }

        // The shape is: a catch around an audit write whose report names the audit. Both halves are needed —
        // `catch` alone matches every swallow in the tree, and the word alone matches the audit classes that
        // never swallow anything.
        if (!\str_contains($contents, 'catch (Throwable')) {
            return false;
        }

        return \array_any(
            self::REPORTED_FAILURES,
            static fn (string $phrase): bool => \str_contains($contents, $phrase),
        );
    }
}
