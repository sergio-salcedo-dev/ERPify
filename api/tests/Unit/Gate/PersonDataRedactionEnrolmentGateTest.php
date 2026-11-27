<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Monitoring\Infrastructure\Monolog\PersonDataRedactionProcessor;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Every channel logger of the PRODUCTION container carries the person-data redaction rule on the logger itself.
 *
 * The rule is enrolled by tag, so a channel logger the container builds without it writes the person's address
 * into a record `FingersCrossedHandler` buffers and flushes to `php://stderr`, the sink nothing erases. The test
 * container proves the behaviour (`PersonDataRedactionArrivalTest`); it cannot prove the production container, which
 * is a different compilation. That was recorded as "a reading, not a gate": 14 enrolments counted by hand with a
 * grep over a purged cache. This reads the dumped container instead.
 *
 * It compiles the prod container by running the console in the `prod` environment (the way the documents proof
 * gates read the same container), then takes every `new \Monolog\Logger('<channel>')` of the dumped class and asks
 * whether the instructions that follow it, up to the end of that service's factory, push the rule.
 *
 * What a green proves: each channel logger the dumper emitted is enrolled, `security` and `request` among them. What it
 * does not: that a logger built at run time (`new Logger()` in application code) carries the rule, that the processor
 * does what it says (the arrival test), or anything about a handler-scoped processor. It reads text the Symfony dumper
 * writes, so a change of the dumper's shape reds it rather than passing silently — the floor below guards that.
 *
 * @internal
 */
#[CoversNothing]
final class PersonDataRedactionEnrolmentGateTest extends TestCase
{
    /**
     * A floor, so a dumper whose shape changed (no `new \Monolog\Logger('…')` found) cannot pass by finding nothing.
     */
    private const int MIN_CHANNEL_LOGGERS = 10;

    /**
     * Channels the rule's own documentation names: both write the records the redaction exists for.
     *
     * @var list<string>
     */
    private const array MUST_BE_PRESENT = ['security', 'request', 'app'];

    #[Test]
    public function everyChannelLoggerOfTheProductionContainerCarriesTheRedactionRule(): void
    {
        $loggers = $this->channelLoggers();

        $this->assertGreaterThanOrEqual(
            self::MIN_CHANNEL_LOGGERS,
            \count($loggers),
            'the compiled prod container yielded too few channel loggers: the dumper shape this gate reads changed',
        );

        foreach (self::MUST_BE_PRESENT as $channel) {
            $this->assertArrayHasKey(
                $channel,
                $loggers,
                \sprintf('the prod container has no "%s" channel logger', $channel),
            );
        }

        $unenrolled = \array_keys(\array_filter($loggers, static fn (bool $enrolled): bool => !$enrolled));

        $this->assertSame(
            [],
            $unenrolled,
            'these channel loggers of the production container do not carry the person-data redaction rule, so a '
            . 'record naming a person reaches the buffered handler unredacted: ' . \implode(', ', $unenrolled),
        );
    }

    /**
     * @return array<string, bool> channel => whether its logger pushes the rule
     */
    private function channelLoggers(): array
    {
        $apiRoot = \dirname(__DIR__, 3);

        // Compiles the container when the cache is absent, and reports where it put it. The child must not inherit the
        // runner markers: `Kernel::getCacheDir()` moves its cache to `prod_phpunit` / `prod_behat` under them, which
        // is neither the directory `php.lint.prod-container` clears and rebuilds nor the one a deploy ships.
        $process = new Process(
            ['php', 'bin/console', 'debug:container', '--parameters', '--format=json', '--no-interaction'],
            $apiRoot,
            ['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'PHPUNIT_RUNNING' => false, 'BEHAT_RUNNING' => false],
        );
        $process->mustRun();

        $parameters = \json_decode($process->getOutput(), true);
        $this->assertIsArray($parameters, 'the prod container parameters could not be read');
        $cacheDir = $parameters['kernel.cache_dir'] ?? null;
        $this->assertIsString($cacheDir, 'the prod container does not say where its cache is');

        $loader = \file_get_contents($cacheDir . '/Erpify_KernelProdContainer.php');
        $this->assertIsString($loader, 'the compiled prod container loader could not be read');
        $named = \preg_match('/\bContainer[A-Za-z0-9]{7}\b/', $loader, $match);
        $this->assertSame(1, $named, 'no container directory named');

        $files = \glob($cacheDir . '/' . $match[0] . '/*.php');
        $this->assertIsArray($files);

        $loggers = [];

        foreach ($files as $file) {
            $source = \file_get_contents($file);
            $this->assertIsString($source, $file . ' could not be read');

            if (!\str_contains($source, 'new \Monolog\Logger(')) {
                continue;
            }

            $matched = \preg_match_all(
                '/new \\\Monolog\\\Logger\(\'([a-z_.]+)\'\)/',
                $source,
                $found,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );

            if (!\is_int($matched) || [] === $found) {
                continue;
            }

            foreach ($found as $index => $match) {
                $channel = $match[1][0];
                $start = $match[0][1];
                $next = isset($found[$index + 1]) ? $found[$index + 1][0][1] : \strlen($source);
                $returned = \strpos($source, "\n        return ", $start);
                $end = false === $returned ? $next : \min($next, $returned);
                $segment = \substr($source, $start, $end - $start);

                $loggers[$channel] = 1 === \preg_match($this->enrolmentPattern(), $segment);
            }
        }

        return $loggers;
    }

    private function enrolmentPattern(): string
    {
        $class = \addcslashes(PersonDataRedactionProcessor::class, '\\');

        return '/pushProcessor\([^\n]*' . \preg_quote($class, '/') . '/';
    }
}
