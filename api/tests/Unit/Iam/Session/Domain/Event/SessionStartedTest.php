<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Event;

use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Event\SessionStarted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SessionStarted::class)]
final class SessionStartedTest extends TestCase
{
    private const string SESSION_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6d';

    private const string USER_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c60';

    private const string EVENT_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6e';

    private const string OCCURRED_ON = '2026-07-10T09:00:00+00:00';

    #[Test]
    public function itExposesItsStableIdentityAndCarriesTheSubjectUserId(): void
    {
        $event = new SessionStarted(
            self::SESSION_ID,
            self::USER_ID,
            self::EVENT_ID,
            new DateTimeImmutable(self::OCCURRED_ON),
        );

        $this->assertSame('erpify.iam.session.started', $event::eventName());
        $this->assertSame('Iam.Session', $event::aggregateType());
        $this->assertSame(self::SESSION_ID, $event->aggregateId());
        $this->assertSame(self::EVENT_ID, $event->eventId());
        $this->assertSame(self::USER_ID, $event->userId());
        $this->assertSame(['userId' => self::USER_ID], $event->toPrimitives());
    }

    #[Test]
    public function fromPrimitivesReconstructsAnIdenticalEvent(): void
    {
        $reconstructed = SessionStarted::fromPrimitives(
            self::SESSION_ID,
            ['userId' => self::USER_ID],
            self::EVENT_ID,
            self::OCCURRED_ON,
        );

        $this->assertSame(self::SESSION_ID, $reconstructed->aggregateId());
        $this->assertSame(self::EVENT_ID, $reconstructed->eventId());
        $this->assertSame(['userId' => self::USER_ID], $reconstructed->toPrimitives());
        $this->assertSame(self::OCCURRED_ON, $reconstructed->occurredOn()->format('c'));
    }
}
