<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Event\PasswordResetRequested;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PasswordResetRequested::class)]
final class PasswordResetRequestedTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string EVENT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e';

    private const string OCCURRED_ON = '2026-07-13T18:00:00+00:00';

    #[Test]
    public function itExposesItsStableIdentityAndCarriesNoPayload(): void
    {
        $event = new PasswordResetRequested(self::USER_ID, self::EVENT_ID, new DateTimeImmutable(self::OCCURRED_ON));

        $this->assertSame('erpify.iam.identity.password-reset-requested', $event::eventName());
        $this->assertSame('Iam.Identity', $event::aggregateType());
        $this->assertSame(self::USER_ID, $event->aggregateId());
        $this->assertSame(self::EVENT_ID, $event->eventId());
        $this->assertSame([], $event->toPrimitives());
    }

    #[Test]
    public function fromPrimitivesReconstructsAnIdenticalEvent(): void
    {
        $event = new PasswordResetRequested(self::USER_ID, self::EVENT_ID, new DateTimeImmutable(self::OCCURRED_ON));

        $reconstructed = PasswordResetRequested::fromPrimitives(self::USER_ID, [], self::EVENT_ID, self::OCCURRED_ON);

        $this->assertSame($event->aggregateId(), $reconstructed->aggregateId());
        $this->assertSame($event->eventId(), $reconstructed->eventId());
        $this->assertSame([], $reconstructed->toPrimitives());
        $this->assertSame($event->occurredOn()->format('c'), $reconstructed->occurredOn()->format('c'));
    }
}
