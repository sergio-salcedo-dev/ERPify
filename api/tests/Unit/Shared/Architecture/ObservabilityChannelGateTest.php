<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Gate over the one property that lets a functional test running under `when@test` say anything about
 * production: the `observability` channel is an always-on stream in BOTH environments, and neither routes it
 * through the buffered handler.
 *
 * Two classes of line depend on it — the cursor-pagination metrics that gave the channel its name, and the
 * report of a swallowed `activity` audit write. Both must arrive on a SUCCESSFUL response, which is exactly
 * what `fingers_crossed` is built to discard. The arrival test proves the audit one reaches
 * `observability.log`; nothing proved that the environment it proves it in resembles the one that matters.
 *
 * Left ungated, the failure is silent in both directions: attach the channel to `fingers_crossed` and the line
 * is discarded again with every test green, or drop the exclusion on `main` and the same record ALSO lands in
 * the buffered handler — which activates it, and activation emits every other record the request buffered,
 * including the authenticated user's email. That second direction is why this gate reads the exclusion list
 * and not merely the handler's existence.
 *
 * **What a green does not prove:** that any line was ever written, that the sink is read, or that the two
 * environments agree on anything else — the `main` handlers legitimately differ (`!event` in test,
 * `!deprecation` in prod), and this gate deliberately does not equate them.
 *
 * @internal
 */
#[CoversNothing]
final class ObservabilityChannelGateTest extends TestCase
{
    private const string CHANNEL = 'observability';

    #[Test]
    #[DataProvider('provideGatedEnvironmentsCases')]
    public function theChannelHasAnAlwaysOnHandlerThatNeverBuffers(string $environment): void
    {
        $handler = $this->handlers($environment)[self::CHANNEL] ?? null;

        $this->assertIsArray($handler, \sprintf(
            'No `%s` handler under `%s`. The channel exists so a line on a successful response reaches a log; '
            . 'without its own handler it falls back to `main`, which discards exactly that.',
            self::CHANNEL,
            $environment,
        ));

        $this->assertSame('stream', $handler['type'] ?? null, \sprintf(
            'The `%s` handler under `%s` is no longer a plain stream. Any buffering type reintroduces the '
            . 'discard this channel exists to escape.',
            self::CHANNEL,
            $environment,
        ));

        $this->assertSame([self::CHANNEL], $handler['channels'] ?? null, \sprintf(
            'The `%s` handler under `%s` no longer serves exactly its own channel.',
            self::CHANNEL,
            $environment,
        ));
    }

    #[Test]
    #[DataProvider('provideGatedEnvironmentsCases')]
    public function theBufferedHandlerExcludesTheChannel(string $environment): void
    {
        $main = $this->handlers($environment)['main'] ?? null;
        $this->assertIsArray($main, \sprintf('No `main` handler under `%s`.', $environment));

        if ('fingers_crossed' !== ($main['type'] ?? null)) {
            $this->markTestSkipped(\sprintf('`main` under `%s` does not buffer; nothing to exclude.', $environment));
        }

        $excluded = $main['channels'] ?? [];
        $this->assertIsArray($excluded, \sprintf('`main` under `%s` declares no channel list.', $environment));

        $this->assertContains('!' . self::CHANNEL, $excluded, \sprintf(
            '`main` under `%s` no longer excludes `%s`. The record would then reach the buffered handler too, '
            . 'and at `error` that ACTIVATES it — flushing every record the request buffered, including the '
            . "authenticated user's identifier, into a sink with no declared owner of its erasure.",
            $environment,
            self::CHANNEL,
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideGatedEnvironmentsCases(): iterable
    {
        yield 'test' => ['when@test'];

        yield 'prod' => ['when@prod'];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function handlers(string $environment): array
    {
        $configFile = \dirname(__DIR__, 4) . '/config/packages/monolog.yaml';
        $this->assertFileExists($configFile);

        $parsed = Yaml::parseFile($configFile);
        $this->assertIsArray($parsed);

        $environmentBlock = $parsed[$environment] ?? null;
        $this->assertIsArray($environmentBlock, \sprintf('monolog.yaml declares no `%s` block.', $environment));

        $monolog = $environmentBlock['monolog'] ?? null;
        $this->assertIsArray($monolog);

        $handlers = $monolog['handlers'] ?? null;
        $this->assertIsArray($handlers);

        return $handlers;
    }
}
