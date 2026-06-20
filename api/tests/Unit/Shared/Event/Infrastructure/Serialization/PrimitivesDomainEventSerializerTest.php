<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Serialization;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Infrastructure\Serialization\PrimitivesDomainEventSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PrimitivesDomainEventSerializer::class)]
final class PrimitivesDomainEventSerializerTest extends TestCase
{
    #[Test]
    public function itMapsTheEventPayloadAndLeavesMetadataEmpty(): void
    {
        $event = $this->createStub(DomainEvent::class);
        $event->method('toPrimitives')->willReturn(['name' => 'Acme', 'shortName' => 'ACME']);

        $envelope = (new PrimitivesDomainEventSerializer())->serialize($event);

        $this->assertSame([
            'payload' => ['name' => 'Acme', 'shortName' => 'ACME'],
            'metadata' => [],
        ], $envelope);
    }
}
