<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\DomainEvent;

use Erpify\Shared\Domain\Event\DomainEvent;

/**
 * Outbound port: persist dispatched domain events for auditing or replay.
 *
 * Infrastructure provides adapters (e.g. relational DB, message log, dual-write). Callers must not
 * depend on a specific backend; bind the interface to one implementation in the service container.
 */
interface DomainEventStore
{
    public function append(DomainEvent $event): void;
}
