<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

use Erpify\Shared\Event\Domain\DomainEvent;

/**
 * Outbound port for the permanent, append-only event log (`event_store`). `append()` joins the
 * use-case write transaction so the aggregate row, this row and the outbox enqueue commit together.
 * `stream()` is the replay read path: everything after a sequence, narrowed to the event names a
 * projector subscribes to, ordered by the global `sequence`.
 */
interface EventStore
{
    public function append(DomainEvent $event): void;

    /**
     * @param list<string> $eventNames
     *
     * @return iterable<StoredEvent>
     */
    public function stream(int $afterSequence, array $eventNames, int $limit): iterable;
}
