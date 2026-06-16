<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

use Erpify\Shared\Event\Domain\DomainEvent;

/**
 * Rebuilds a typed {@see DomainEvent} from a stored row: resolve the class via the mapper, run the
 * stored payload through the {@see Upcaster} chain up to the current schema version, then hand it to
 * the event's own {@see DomainEvent::fromPrimitives()}.
 */
interface DomainEventDeserializer
{
    public function deserialize(StoredEvent $storedEvent): DomainEvent;
}
