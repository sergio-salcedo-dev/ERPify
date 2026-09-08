<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother;

use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The sibling of {@see SessionMotherTest}, over the shorter fuse: {@see PasswordResetTokenMother}'s default
 * expiry was `2030-01-01T00:00:00+00:00` while its own docblock said the row "lapses within the hour" — a
 * literal outliving by four years the window it claimed to model, which is how a seed stops describing
 * anything and starts merely being far away.
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
