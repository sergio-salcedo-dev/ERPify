<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother;

use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one property of {@see SessionMother} worth pinning: its default expiry is measured from the clock the
 * test is running on, never from a literal on the calendar.
 *
 * It exists because the absolute form is not a hypothetical failure here. The default was
 * `2099-01-01T00:00:00+00:00`, and what kept every consumer that does not freeze the clock green was that
 * date still being in the future — the same shape, and the same silence, as the seeds that expired under
 * the suite in September 2026 and turned three assertions vacuous on their way past. A far fuse is not the
 * absence of the bomb.
 *
 * The instant below is deliberately past the retired literal: with it, the absolute form fails and the
 * relative one cannot, which is the only difference this test is able to see.
 *
 * @internal
 */
#[CoversNothing]
final class SessionMotherTest extends TestCase
{
    #[Test]
    public function itsDefaultExpiryFollowsTheClockRatherThanTheCalendar(): void
    {
        SystemClock::set(FixedClock::at('2100-01-01T00:00:00+00:00'));

        $this->assertTrue(SessionMother::active()->isActive(SystemClock::now()));
    }
}
