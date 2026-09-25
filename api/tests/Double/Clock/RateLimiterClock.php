<?php

declare(strict_types=1);

namespace Erpify\Tests\Double\Clock;

use LogicException;

/**
 * The time source `symfony/rate-limiter` reads, made controllable for a test.
 *
 * The component takes no clock: `SlidingWindow`, `SlidingWindowLimiter`, `InMemoryStorage` and `RateLimit` call
 * `microtime(true)` unqualified, so the only seam is a function of that name declared in their namespaces —
 * `api/tests/Double/Clock/rate-limiter-microtime.php`, loaded from `api/tools/phpunit/bootstrap.php`. It must
 * be declared before the first rate-limiter call of the process: PHP caches what an unqualified call resolved to
 * per call site, so a shim declared after a limiter already ran is never reached by it.
 *
 * Released, it is the wall clock, so a test that never freezes it sees exactly what it saw before the shim.
 * A test that freezes it releases it in `tearDown()`, or every limiter after it in the process stops rolling.
 *
 * {@see self::reads()} is what separates "the limiter saw the frozen instant" from "the shim was never reached":
 * a window that should have rolled and did not is red either way, but only the counter says which.
 */
final class RateLimiterClock
{
    private static ?float $frozenAt = null;

    private static int $reads = 0;

    public static function freeze(?float $at = null): void
    {
        self::$frozenAt = $at ?? \microtime(true);
        self::$reads = 0;
    }

    public static function advance(float $seconds): void
    {
        if (null === self::$frozenAt) {
            throw new LogicException('Freeze the rate-limiter clock before advancing it.');
        }

        self::$frozenAt += $seconds;
    }

    public static function release(): void
    {
        self::$frozenAt = null;
        self::$reads = 0;
    }

    public static function reads(): int
    {
        return self::$reads;
    }

    public static function microtime(bool $asFloat): float|string
    {
        if (null === self::$frozenAt) {
            return \microtime($asFloat);
        }

        ++self::$reads;

        if ($asFloat) {
            return self::$frozenAt;
        }

        $seconds = \floor(self::$frozenAt);

        return \sprintf('%.8F %d', self::$frozenAt - $seconds, (int) $seconds);
    }
}
