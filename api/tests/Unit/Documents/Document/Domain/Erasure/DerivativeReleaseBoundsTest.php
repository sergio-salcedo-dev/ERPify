<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain\Erasure;

use Erpify\Documents\Document\Domain\Erasure\ErasureLimits;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The derivative release's two bounds are judged against the retries the async transport actually grants, read from
 * `config/packages/messenger.yaml`, so raising the retries or their delays turns this red instead of leaving a
 * re-request that duplicates one still being retried, or an alarm that fires while the last attempt is pending.
 * Each wait is taken at its worst: the full delay plus Symfony's jitter (0.1 unless the strategy says otherwise),
 * then capped, as `MultiplierRetryStrategy::getWaitingTime()` caps it.
 *
 * @internal
 */
#[CoversNothing]
final class DerivativeReleaseBoundsTest extends TestCase
{
    private const string MESSENGER = __DIR__ . '/../../../../../../config/packages/messenger.yaml';

    private const float SYMFONY_DEFAULT_JITTER = 0.1;

    #[Test]
    public function aReRequestComesOnlyAfterEveryRetryOfTheLastOneIsOver(): void
    {
        $this->assertGreaterThan($this->retryHorizonSeconds(), ErasureLimits::DERIVATIVE_REREQUEST_SECONDS);
    }

    #[Test]
    public function theAlarmWaitsForTheReRequestAndEveryRetryOfIt(): void
    {
        $this->assertGreaterThanOrEqual(
            ErasureLimits::DERIVATIVE_REREQUEST_SECONDS + $this->retryHorizonSeconds(),
            ErasureLimits::DERIVATIVE_ALARM_SECONDS,
        );
    }

    #[Test]
    public function theHorizonIsReadFromARealStrategyRatherThanDefaultedToNothing(): void
    {
        // 10 retries from 1 s tripling to an hour's cap: about three hours. A misread file would answer zero.
        $this->assertGreaterThan(3 * 3_600 - 1_000, $this->retryHorizonSeconds());
    }

    private function retryHorizonSeconds(): float
    {
        $strategy = Yaml::parseFile(self::MESSENGER);

        foreach (['framework', 'messenger', 'transports', 'async', 'retry_strategy'] as $key) {
            $this->assertIsArray($strategy, 'the async transport declares its retry strategy');
            $strategy = $strategy[$key] ?? null;
        }

        $this->assertIsArray($strategy, 'the async transport declares its retry strategy');

        $retries = $this->intOf($strategy, 'max_retries');
        $delay = $this->intOf($strategy, 'delay');
        $multiplier = $strategy['multiplier'] ?? null;
        $this->assertIsNumeric($multiplier);
        $cap = $this->intOf($strategy, 'max_delay');
        $jitter = $strategy['jitter'] ?? self::SYMFONY_DEFAULT_JITTER;
        $this->assertIsNumeric($jitter);

        $total = 0.0;

        for ($retry = 0; $retry < $retries; ++$retry) {
            $wait = $delay * ((float) $multiplier) ** $retry * (1 + (float) $jitter);
            $total += 0 === $cap ? $wait : \min($wait, $cap);
        }

        return $total / 1_000;
    }

    /**
     * @param array<mixed> $strategy
     */
    private function intOf(array $strategy, string $key): int
    {
        $value = $strategy[$key] ?? null;
        $this->assertIsInt($value, $key);

        return $value;
    }
}
