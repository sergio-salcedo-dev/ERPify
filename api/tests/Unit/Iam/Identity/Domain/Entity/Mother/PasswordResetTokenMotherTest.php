<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother;

use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The sibling of {@see SessionMotherTest}, over a mother whose docblock promises a row that "lapses within
 * the hour". A window measured from the clock is the only form in which that promise is checkable at all:
 * an absolute literal states a date, and a date says nothing about how long anything lasts.
 *
 * @internal
 */
#[CoversNothing]
final class PasswordResetTokenMotherTest extends TestCase
{
    #[Test]
    public function itsDefaultExpiryFollowsTheClockRatherThanTheCalendar(): void
    {
        SystemClock::set(FixedClock::at('2100-01-01T00:00:00+00:00'));

        $this->assertFalse(PasswordResetTokenMother::pendingFor()->isExpiredAt(SystemClock::now()));
    }
}
