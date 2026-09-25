<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother;

use DateInterval;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The properties of {@see SessionMother} worth pinning: its default expiry is measured from the clock the test
 * is running on, never from a literal on the calendar; and a session it builds is one a minting could have
 * produced — never created after it expires, with the ambient clock left exactly as the test set it.
 *
 * An absolute far-future expiry is not the absence of the bomb, it is the same bomb with a longer fuse: a
 * consumer that does not freeze the clock is green only for as long as that date stays in the future, and
 * the failure arrives on a commit that touched nothing. A relative window cannot have that property.
 *
 * The instant in the first case sits past any far-future literal a mother would plausibly carry, which is the
 * only difference that case is able to see: with it, an absolute default fails and a relative one cannot.
 *
 * @internal
 */
#[CoversNothing]
final class SessionMotherTest extends TestCase
{
    private const string INSTANT = 'Y-m-d\TH:i:s.uP';

    #[Test]
    public function itsDefaultExpiryFollowsTheClockRatherThanTheCalendar(): void
    {
        SystemClock::set(FixedClock::at('2100-01-01T00:00:00+00:00'));

        $this->assertTrue(SessionMother::active()->isActive(SystemClock::now()));
    }

    #[Test]
    public function anAlreadyExpiredSessionIsCreatedOneTtlBeforeItsExpiry(): void
    {
        $expiresAt = SystemClock::now()->modify('-91 days');

        $session = SessionMother::active(expiresAt: $expiresAt);

        $this->assertLessThan($session->expiresAt(), $session->getCreatedAt());
        $this->assertSame(
            $expiresAt->sub(new DateInterval(SessionMother::DEFAULT_TTL_SPEC))->format(self::INSTANT),
            $session->getCreatedAt()->format(self::INSTANT),
        );
    }

    #[Test]
    public function aStillValidSessionIsCreatedAtTheAmbientInstant(): void
    {
        $session = SessionMother::active(expiresAt: SystemClock::now()->modify('+1 hour'));

        $this->assertSame(SystemClock::now()->format(self::INSTANT), $session->getCreatedAt()->format(self::INSTANT));
    }

    #[Test]
    public function buildingAnExpiredSessionPutsTheSameAmbientClockObjectBack(): void
    {
        $clock = new FixedClock(SystemClock::now());
        SystemClock::set($clock);

        SessionMother::active(expiresAt: SystemClock::now()->modify('-1 hour'));

        $this->assertSame($clock, (new ReflectionProperty(SystemClock::class, 'clock'))->getValue());
    }
}
