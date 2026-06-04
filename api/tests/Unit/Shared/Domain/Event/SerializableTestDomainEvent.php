<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Event;

use Erpify\Shared\Domain\Event\DomainEvent;
use Override;

/**
 * Named (non-anonymous) fixture: PHP forbids serializing anonymous classes, and
 * {@see DomainEventTest} needs a real serialize()/unserialize() round trip.
 */
final class SerializableTestDomainEvent extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'test.event.occurred';
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return ['aggregateId' => $this->aggregateId()];
    }
}
