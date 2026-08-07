<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Domain\Event;

use DateTimeImmutable;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Event\CarriesInvitationSnapshot;
use Erpify\Iam\Invitation\Domain\Event\InvitationAccepted;
use Erpify\Iam\Invitation\Domain\Event\InvitationCreated;
use Erpify\Iam\Invitation\Domain\Event\InvitationExpired;
use Erpify\Iam\Invitation\Domain\Event\InvitationResent;
use Erpify\Iam\Invitation\Domain\Event\InvitationRevoked;
use Erpify\Iam\Invitation\Domain\Event\InvitationSent;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Token\Domain\SingleUseToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The six invitation events share one envelope via {@see CarriesInvitationSnapshot}: the invited user IS the
 * aggregateId and the payload is empty. Each event keeps its own stable name.
 *
 * The envelope invariants are asserted over a **discovered** set rather than a listed one, because the thing
 * being defended is a security property of the whole family: a seventh event added to the module inherits the
 * assertions without anyone remembering to extend a list. The stable names below stay enumerated on purpose —
 * a name is a literal contract with the stored log, and discovering it would only compare the code to itself.
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
    /**
     * The floor exists so a discovery that silently finds nothing fails instead of passing over an empty set;
     * it is not an enumeration, so a seventh event raises it without editing anything.
     */
    private const int KNOWN_EVENT_COUNT = 6;

    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5c20';

    private const string INVITED_USER_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5c21';

    private const string EVENT_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5c22';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5c23';

    private const string OCCURRED_ON = '2026-07-13T10:00:00+00:00';

    #[Test]
    public function theEnvelopeNamesTheInvitedUserAndRoundTripsWithNoPayload(): void
    {
        $event = new InvitationCreated(
            self::INVITED_USER_ID,
            self::EVENT_ID,
            new DateTimeImmutable(self::OCCURRED_ON),
        );

        $this->assertSame('Iam.Invitation', $event::aggregateType());
        $this->assertSame(self::INVITED_USER_ID, $event->aggregateId());
        $this->assertSame(self::INVITED_USER_ID, $event->invitedUserId());
        $this->assertSame([], $event->toPrimitives());

        $reconstructed = InvitationCreated::fromPrimitives(
            self::INVITED_USER_ID,
            [],
            self::EVENT_ID,
            self::OCCURRED_ON,
        );

        $this->assertSame(self::INVITED_USER_ID, $reconstructed->invitedUserId());
        $this->assertSame(self::EVENT_ID, $reconstructed->eventId());
        $this->assertSame(self::OCCURRED_ON, $reconstructed->occurredOn()->format('c'));
    }

    /**
     * The envelope contract, asserted over every class that shares the trait rather than over a list.
     */
    #[Test]
    public function everyEventSharingTheEnvelopeIsKeyedByItsSubjectAtSchemaVersionTwo(): void
    {
        $eventClasses = $this->eventsSharingTheEnvelope();

        $this->assertGreaterThanOrEqual(self::KNOWN_EVENT_COUNT, \count($eventClasses));

        foreach ($eventClasses as $eventClass) {
            $event = new $eventClass(self::INVITED_USER_ID);

            $this->assertSame('Iam.Invitation', $eventClass::aggregateType(), $eventClass);
            $this->assertSame(2, $eventClass::eventVersion(), $eventClass);
            $this->assertSame(self::INVITED_USER_ID, $event->aggregateId(), $eventClass);
            $this->assertSame([], $event->toPrimitives(), $eventClass);
        }
    }

    /**
     * The security invariant, driven through the aggregate rather than through the constructors: the invitation
     * id is the selector half of the acceptance link and keys its accept budget, so no lifecycle event may
     * carry it into `event_store` — not as the aggregate column, and not anywhere inside the payload.
     */
    #[Test]
    public function noLifecycleEventCarriesTheInvitationIdInItsEnvelopeOrItsPayload(): void
    {
        $events = $this->everyLifecycleEvent();

        $this->assertGreaterThanOrEqual(self::KNOWN_EVENT_COUNT, \count($events));

        foreach ($events as $event) {
            $written = \json_encode(
                ['aggregateId' => $event->aggregateId(), 'payload' => $event->toPrimitives()],
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame(self::INVITED_USER_ID, $event->aggregateId(), $event::eventName());
            $this->assertStringNotContainsStringIgnoringCase(
                self::INVITATION_ID,
                $written,
                $event::eventName() . ' writes the acceptance selector to the event store',
            );
        }
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

    /**
     * @return list<class-string<DomainEvent>>
     */
    private function eventsSharingTheEnvelope(): array
    {
        $eventClasses = [];
        $files = \glob(__DIR__ . '/../../../../../../src/Iam/Invitation/Domain/Event/*.php') ?: [];

        foreach ($files as $file) {
            $candidate = 'Erpify\Iam\Invitation\Domain\Event\\' . \basename($file, '.php');

            if (!\class_exists($candidate)) {
                continue;
            }

            if (!\is_subclass_of($candidate, DomainEvent::class)) {
                continue;
            }

            if (\in_array(CarriesInvitationSnapshot::class, (new ReflectionClass($candidate))->getTraitNames(), true)) {
                $eventClasses[] = $candidate;
            }
        }

        return $eventClasses;
    }

    /**
     * Every event the aggregate can record, collected by walking each terminal branch of its lifecycle.
     *
     * @return list<DomainEvent>
     */
    private function everyLifecycleEvent(): array
    {
        $accepted = $this->sentInvitation();
        $accepted->accept();

        $revoked = $this->sentInvitation();
        $revoked->revoke();

        $expired = $this->sentInvitation();
        $expired->expire();

        $resent = $this->sentInvitation();
        $resent->resend(SingleUseToken::mint(new DateTimeImmutable(self::OCCURRED_ON))->token);

        return [
            ...$accepted->pullDomainEvents(),
            ...$revoked->pullDomainEvents(),
            ...$expired->pullDomainEvents(),
            ...$resent->pullDomainEvents(),
        ];
    }

    private function sentInvitation(): Invitation
    {
        $invitation = Invitation::create(
            self::INVITATION_ID,
            self::ORG_ID,
            self::INVITED_USER_ID,
            SingleUseToken::mint(new DateTimeImmutable(self::OCCURRED_ON))->token,
        );
        $invitation->markSent();

        return $invitation;
    }
}
