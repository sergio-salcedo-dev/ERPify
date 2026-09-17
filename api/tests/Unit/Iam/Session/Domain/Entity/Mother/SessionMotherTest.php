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
 * An absolute far-future expiry is not the absence of the bomb, it is the same bomb with a longer fuse: a
 * consumer that does not freeze the clock is green only for as long as that date stays in the future, and
 * the failure arrives on a commit that touched nothing. A relative window cannot have that property.
 *
 * The instant below sits past any far-future literal a mother would plausibly carry, which is the only
 * difference this test is able to see: with it, an absolute default fails and a relative one cannot.
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
