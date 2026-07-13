<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Domain\Event;

use DateTimeImmutable;
use Erpify\Iam\Invitation\Domain\Event\CarriesInvitationSnapshot;
use Erpify\Iam\Invitation\Domain\Event\InvitationAccepted;
use Erpify\Iam\Invitation\Domain\Event\InvitationCreated;
use Erpify\Iam\Invitation\Domain\Event\InvitationExpired;
use Erpify\Iam\Invitation\Domain\Event\InvitationResent;
use Erpify\Iam\Invitation\Domain\Event\InvitationRevoked;
use Erpify\Iam\Invitation\Domain\Event\InvitationSent;
use Erpify\Shared\Event\Domain\DomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The six invitation events share one PII-free envelope via {@see CarriesInvitationSnapshot}: the invitation id
 * is the aggregateId, the payload is only the `invitedUserId`. Each event keeps its own stable name.
 *
 * @internal
 */
#[CoversClass(CarriesInvitationSnapshot::class)]
#[CoversClass(InvitationCreated::class)]
#[CoversClass(InvitationSent::class)]
#[CoversClass(InvitationResent::class)]
#[CoversClass(InvitationRevoked::class)]
#[CoversClass(InvitationExpired::class)]
#[CoversClass(InvitationAccepted::class)]
final class InvitationEventsTest extends TestCase
{
    private const string AGGREGATE_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5c20';

    private const string INVITED_USER_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5c21';

    private const string EVENT_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5c22';

    private const string OCCURRED_ON = '2026-07-13T10:00:00+00:00';

    #[Test]
    public function itCarriesThePiiFreeSnapshotAndRoundTrips(): void
    {
        $event = new InvitationCreated(
            self::AGGREGATE_ID,
            self::INVITED_USER_ID,
            self::EVENT_ID,
            new DateTimeImmutable(self::OCCURRED_ON),
        );

        $this->assertSame('Iam.Invitation', $event::aggregateType());
        $this->assertSame(self::AGGREGATE_ID, $event->aggregateId());
        $this->assertSame(self::INVITED_USER_ID, $event->invitedUserId());
        $this->assertSame(['invitedUserId' => self::INVITED_USER_ID], $event->toPrimitives());

        $reconstructed = InvitationCreated::fromPrimitives(
            self::AGGREGATE_ID,
            ['invitedUserId' => self::INVITED_USER_ID],
            self::EVENT_ID,
            self::OCCURRED_ON,
        );

        $this->assertSame(self::INVITED_USER_ID, $reconstructed->invitedUserId());
        $this->assertSame(self::EVENT_ID, $reconstructed->eventId());
        $this->assertSame(self::OCCURRED_ON, $reconstructed->occurredOn()->format('c'));
    }

    /**
     * @param class-string<DomainEvent> $eventClass
     */
    #[Test]
    #[DataProvider('provideEachEventHasItsStableNameCases')]
    public function eachEventHasItsStableName(string $eventClass, string $expectedName): void
    {
        $this->assertSame($expectedName, $eventClass::eventName());
        $this->assertSame('Iam.Invitation', $eventClass::aggregateType());
    }

    /**
     * @return iterable<string, array{class-string<DomainEvent>, string}>
     */
    public static function provideEachEventHasItsStableNameCases(): iterable
    {
        yield 'created' => [InvitationCreated::class, 'erpify.iam.invitation.created'];
        yield 'sent' => [InvitationSent::class, 'erpify.iam.invitation.sent'];
        yield 'resent' => [InvitationResent::class, 'erpify.iam.invitation.resent'];
        yield 'revoked' => [InvitationRevoked::class, 'erpify.iam.invitation.revoked'];
        yield 'expired' => [InvitationExpired::class, 'erpify.iam.invitation.expired'];
        yield 'accepted' => [InvitationAccepted::class, 'erpify.iam.invitation.accepted'];
    }
}
