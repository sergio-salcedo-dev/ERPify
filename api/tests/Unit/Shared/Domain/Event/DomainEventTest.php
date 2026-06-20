<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DomainEvent::class)]
final class DomainEventTest extends TestCase
{
    public function testEventIdSurvivesAPhpSerializeRoundTrip(): void
    {
        // Messenger's default PhpSerializer transports events via serialize()/unserialize(), which
        // bypasses the constructor — the id minted at construction must reach consumers unchanged.
        $event = new SerializableTestDomainEvent('aggregate-id');

        $roundTripped = \unserialize(\serialize($event));

        $this->assertInstanceOf(SerializableTestDomainEvent::class, $roundTripped);
        $this->assertSame($event->eventId(), $roundTripped->eventId());
        $this->assertSame($event->aggregateId(), $roundTripped->aggregateId());
    }

    public function testInjectedEnvelopeIsPreservedAndVersionDefaultsToOne(): void
    {
        $eventId = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
        $occurredOn = new DateTimeImmutable('2026-06-06T00:00:00+00:00');

        $event = new SerializableTestDomainEvent('aggregate-id', $eventId, $occurredOn);

        $this->assertSame('aggregate-id', $event->aggregateId());
        $this->assertSame($eventId, $event->eventId());
        $this->assertSame($occurredOn, $event->occurredOn());
        $this->assertSame(1, $event::eventVersion());
    }

    public function testEnvelopeIsMintedWhenNotInjected(): void
    {
        $event = new SerializableTestDomainEvent('aggregate-id');

        $this->assertNotSame('', $event->eventId());
        $this->assertEqualsWithDelta(
            (new DateTimeImmutable())->getTimestamp(),
            $event->occurredOn()->getTimestamp(),
            5,
        );
    }
}
