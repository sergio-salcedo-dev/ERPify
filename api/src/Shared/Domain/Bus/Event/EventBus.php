<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Bus\Event;

use Erpify\Shared\Domain\Event\DomainEvent;

/**
 * Output port for publishing domain events (Codely-style). The application layer depends on this
 * interface, never on a concrete message bus, so persistence and publication can be committed
 * atomically at the use-case boundary while the framework transport stays in Infrastructure.
 *
 * The single adapter publishes to the transactional outbox (Doctrine transport); a broker, if ever
 * adopted, sits downstream of that outbox — never as a direct publish target. See
 * docs/adr/event-driven-architecture.md.
 */
interface EventBus
{
    public function publish(DomainEvent ...$events): void;
}
