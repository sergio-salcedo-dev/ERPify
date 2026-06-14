<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Clock;

use DateTimeInterface;
use Erpify\Shared\Infrastructure\Clock\SymfonyClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * @internal
 */
#[CoversClass(SymfonyClock::class)]
final class SymfonyClockTest extends TestCase
{
    #[Test]
    public function itReturnsTheInstantOfTheUnderlyingClock(): void
    {
        $instant = '2026-01-02T03:04:05+00:00';
        $clock = new SymfonyClock(new MockClock($instant));

        self::assertSame($instant, $clock->now()->format(DateTimeInterface::ATOM));
    }
}
