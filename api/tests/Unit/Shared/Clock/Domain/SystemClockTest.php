<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Clock\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Shared\Clock\Domain\NativeClock;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Clock\Infrastructure\SymfonyClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * @internal
 */
#[CoversClass(SystemClock::class)]
#[CoversClass(NativeClock::class)]
final class SystemClockTest extends TestCase
{
    protected function tearDown(): void
    {
        SystemClock::reset();

        parent::tearDown();
    }

    #[Test]
    public function itReadsTheInstantOfTheClockItWasGiven(): void
    {
        $instant = '2026-01-02T03:04:05+00:00';
        SystemClock::set(new SymfonyClock(new MockClock($instant)));

        $this->assertSame($instant, SystemClock::now()->format(DateTimeInterface::ATOM));
    }

    #[Test]
    public function itFallsBackToTheHostWallClockWhenNoneIsSet(): void
    {
        SystemClock::reset();

        $before = new DateTimeImmutable();
        $now = SystemClock::now();
        $after = new DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual($after, $now);
    }

    #[Test]
    public function resetRestoresTheHostWallClockAfterAFrozenClock(): void
    {
        SystemClock::set(new SymfonyClock(new MockClock('2000-01-01T00:00:00+00:00')));
        SystemClock::reset();

        $this->assertGreaterThan(new DateTimeImmutable('2020-01-01T00:00:00+00:00'), SystemClock::now());
    }
}
