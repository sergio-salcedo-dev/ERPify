<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Domain\Event\DomainEvent;
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
        $event = new SerializableTestDomainEvent('aggregate-id', new DateTimeImmutable());

        $roundTripped = \unserialize(\serialize($event));

        $this->assertInstanceOf(SerializableTestDomainEvent::class, $roundTripped);
        $this->assertSame($event->eventId(), $roundTripped->eventId());
        $this->assertSame($event->aggregateId(), $roundTripped->aggregateId());
    }
}
