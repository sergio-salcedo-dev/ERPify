<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Application;

use Erpify\Shared\Event\Application\StoredEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StoredEvent::class)]
final class StoredEventTest extends TestCase
{
    #[Test]
    public function itExposesEveryColumnOfTheStoredRow(): void
    {
        $event = new StoredEvent(
            7,
            'event-id',
            'aggregate-id',
            'Backoffice.Bank',
            3,
            'erpify.backoffice.bank.created',
            1,
            ['name' => 'Acme'],
            ['actor' => 'system'],
            'tenant-id',
            '2026-06-06T00:00:00+00:00',
            '2026-06-06T00:00:01+00:00',
        );

        $this->assertSame(7, $event->sequence);
        $this->assertSame('event-id', $event->eventId);
        $this->assertSame('aggregate-id', $event->aggregateId);
        $this->assertSame('Backoffice.Bank', $event->aggregateType);
        $this->assertSame(3, $event->aggregateVersion);
        $this->assertSame('erpify.backoffice.bank.created', $event->eventName);
        $this->assertSame(1, $event->eventVersion);
        $this->assertSame(['name' => 'Acme'], $event->payload);
        $this->assertSame(['actor' => 'system'], $event->metadata);
        $this->assertSame('tenant-id', $event->tenantId);
        $this->assertSame('2026-06-06T00:00:00+00:00', $event->occurredOn);
        $this->assertSame('2026-06-06T00:00:01+00:00', $event->recordedOn);
    }
}
