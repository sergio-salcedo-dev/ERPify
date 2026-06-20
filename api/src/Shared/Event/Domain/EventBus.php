<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Domain;

/**
 * Output port for publishing domain events (Codely-style). The application layer depends on this
 * interface, never on a concrete message bus, so persistence and publication can be committed
 * atomically at the use-case boundary while the framework transport stays in Infrastructure.
 *
 * The single adapter publishes to the transactional outbox (Doctrine transport); a broker, if ever
 * adopted, sits downstream of the event store — never as a direct publish target. See
 * docs/adr/event-store-and-projections.md.
 */
interface EventBus
{
    public function publish(DomainEvent ...$events): void;
}
