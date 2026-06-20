<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Serialization;

use Erpify\Shared\Event\Application\StoredEvent;
use Erpify\Shared\Event\Application\Upcaster;
use Erpify\Shared\Event\Domain\DomainEventMapper;
use Erpify\Shared\Event\Infrastructure\Serialization\PrimitivesDomainEventDeserializer;
use Erpify\Tests\Unit\Shared\Domain\Event\SerializableTestDomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PrimitivesDomainEventDeserializer::class)]
final class PrimitivesDomainEventDeserializerTest extends TestCase
{
    #[Test]
    public function itUpcastsResolvesAndRebuildsTheEventFromTheStoredRow(): void
    {
        $payload = ['aggregateId' => 'agg-1'];
        $upcastPayload = ['aggregateId' => 'agg-1', 'added' => true];

        $upcaster = $this->createMock(Upcaster::class);
        $upcaster->expects($this->once())
            ->method('upcast')
            ->with('test.event.occurred', 1, $payload)
            ->willReturn($upcastPayload)
        ;
        $upcaster->method('targetVersion')->willReturn(2);

        $mapper = $this->createMock(DomainEventMapper::class);
        $mapper->expects($this->once())
            ->method('classFor')
            ->with('test.event.occurred', 2)
            ->willReturn(SerializableTestDomainEvent::class)
        ;

        $deserializer = new PrimitivesDomainEventDeserializer($mapper, $upcaster);

        $event = $deserializer->deserialize($this->storedEvent($payload));

        $this->assertInstanceOf(SerializableTestDomainEvent::class, $event);
        $this->assertSame('agg-1', $event->aggregateId());
        $this->assertSame('event-1', $event->eventId());
        $this->assertSame('2026-06-06T00:00:00+00:00', $event->occurredOn()->format('c'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storedEvent(array $payload): StoredEvent
    {
        return new StoredEvent(
            42,
            'event-1',
            'agg-1',
            'Test.Aggregate',
            1,
            'test.event.occurred',
            1,
            $payload,
            [],
            null,
            '2026-06-06T00:00:00+00:00',
            '2026-06-06T00:00:01+00:00',
        );
    }
}
