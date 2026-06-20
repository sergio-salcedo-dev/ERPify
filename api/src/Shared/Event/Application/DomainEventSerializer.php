<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

use Erpify\Shared\Event\Domain\DomainEvent;

/**
 * Maps a {@see DomainEvent} to the two JSONB columns of an `event_store` row: `payload` (domain data
 * from {@see DomainEvent::toPrimitives()}) and `metadata` (envelope: correlation/causation/actor —
 * reserved, empty today).
 */
interface DomainEventSerializer
{
    /**
     * @return array{payload: array<string, mixed>, metadata: array<string, mixed>}
     */
    public function serialize(DomainEvent $event): array;
}
