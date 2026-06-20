<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

/**
 * Read DTO for one `event_store` row, as returned by {@see EventStore::stream()} for replay and
 * projection catch-up. `occurredOn`/`recordedOn` are the ISO-8601 string forms (the deserializer
 * casts `occurredOn` back to a typed value via {@see \Erpify\Shared\Event\Domain\DomainEvent::fromPrimitives()}).
 */
final readonly class StoredEvent
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function __construct(
        public int $sequence,
        public string $eventId,
        public string $aggregateId,
        public string $aggregateType,
        public int $aggregateVersion,
        public string $eventName,
        public int $eventVersion,
        public array $payload,
        public array $metadata,
        public ?string $tenantId,
        public string $occurredOn,
        public string $recordedOn,
    ) {
    }
}
