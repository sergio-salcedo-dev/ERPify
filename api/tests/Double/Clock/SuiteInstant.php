<?php

declare(strict_types=1);

namespace Erpify\Tests\Double\Clock;

use DateTimeImmutable;
use Erpify\Tests\Support\PHPUnit\FreezeSystemClockExtension;

/**
 * The instant a test hands an aggregate when the test does not care what time it is.
 *
 * It is the instant {@see FreezeSystemClockExtension} pins Symfony's global clock to, so an aggregate a test
 * builds and a request the container then serves agree on "now" without either reading the other. A test that
 * DOES care states its own instant instead — through a {@see FixedClock} it injects into the use case, or a
 * `DateTimeImmutable` it passes to the factory — and then everything the aggregate stamps is derived from it.
 */
final class SuiteInstant
{
    /**
     * Static-only holder; nothing constructs it.
     */
    private function __construct()
    {
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(FreezeSystemClockExtension::SUITE_INSTANT);
    }

    public static function clock(): FixedClock
    {
        return FixedClock::at(FreezeSystemClockExtension::SUITE_INSTANT);
    }
}
