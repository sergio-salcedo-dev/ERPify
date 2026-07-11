<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Event;

use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Event\OtherSessionsRevoked;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OtherSessionsRevoked::class)]
final class OtherSessionsRevokedTest extends TestCase
{
    private const string USER_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c60';

    private const string KEPT_SESSION_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c61';

    private const string EVENT_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c70';

    private const string OCCURRED_ON = '2026-07-10T09:45:00+00:00';

    #[Test]
    public function itIsAUserSubjectedFactCarryingTheKeptSessionId(): void
    {
        $event = new OtherSessionsRevoked(
            self::USER_ID,
            self::KEPT_SESSION_ID,
            self::EVENT_ID,
            new DateTimeImmutable(self::OCCURRED_ON),
        );

        $this->assertSame('erpify.iam.session.others-revoked', $event::eventName());
        $this->assertSame('Iam.Session', $event::aggregateType());
        $this->assertSame(self::USER_ID, $event->aggregateId());
        $this->assertSame(self::EVENT_ID, $event->eventId());
        $this->assertSame(self::KEPT_SESSION_ID, $event->keptSessionId());
        $this->assertSame(['keptSessionId' => self::KEPT_SESSION_ID], $event->toPrimitives());
    }

    #[Test]
    public function fromPrimitivesReconstructsAnIdenticalEvent(): void
    {
        $reconstructed = OtherSessionsRevoked::fromPrimitives(
            self::USER_ID,
            ['keptSessionId' => self::KEPT_SESSION_ID],
            self::EVENT_ID,
            self::OCCURRED_ON,
        );

        $this->assertSame(self::USER_ID, $reconstructed->aggregateId());
        $this->assertSame(self::EVENT_ID, $reconstructed->eventId());
        $this->assertSame(self::KEPT_SESSION_ID, $reconstructed->keptSessionId());
        $this->assertSame(['keptSessionId' => self::KEPT_SESSION_ID], $reconstructed->toPrimitives());
        $this->assertSame(self::OCCURRED_ON, $reconstructed->occurredOn()->format('c'));
    }
}
