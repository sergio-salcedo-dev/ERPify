<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Iam\Identity\Domain\Event\UserLocked;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UserLocked::class)]
final class UserLockedTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string EVENT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e';

    private const string OCCURRED_ON = '2026-07-11T12:00:00+00:00';

    private const string LOCKED_UNTIL = '2026-07-11T12:15:00+00:00';

    #[Test]
    public function itExposesItsStableIdentityAndCarriesTheExpiryFreeOfPii(): void
    {
        $event = $this->event();

        $this->assertSame('erpify.iam.identity.locked', $event::eventName());
        $this->assertSame('Iam.Identity', $event::aggregateType());
        $this->assertSame(self::USER_ID, $event->aggregateId());
        $this->assertSame(self::EVENT_ID, $event->eventId());
        $this->assertSame(['lockedUntil' => self::LOCKED_UNTIL], $event->toPrimitives());
    }

    #[Test]
    public function fromPrimitivesReconstructsAnIdenticalEvent(): void
    {
        $event = $this->event();

        $reconstructed = UserLocked::fromPrimitives(
            self::USER_ID,
            ['lockedUntil' => self::LOCKED_UNTIL],
            self::EVENT_ID,
            self::OCCURRED_ON,
        );

        $this->assertSame($event->aggregateId(), $reconstructed->aggregateId());
        $this->assertSame($event->eventId(), $reconstructed->eventId());
        $this->assertSame($event->toPrimitives(), $reconstructed->toPrimitives());
        $this->assertSame(
            $event->lockedUntil()->format(DateTimeInterface::ATOM),
            $reconstructed->lockedUntil()->format(DateTimeInterface::ATOM),
        );
        $this->assertSame($event->occurredOn()->format('c'), $reconstructed->occurredOn()->format('c'));
    }

    private function event(): UserLocked
    {
        return new UserLocked(
            self::USER_ID,
            new DateTimeImmutable(self::LOCKED_UNTIL),
            self::EVENT_ID,
            new DateTimeImmutable(self::OCCURRED_ON),
        );
    }
}
