<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * A second event was appended to the same aggregate stream concurrently, so the optimistic
 * version assigned by {@see \Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStore} lost
 * the race on the stream's unique constraint. It is a 409 the caller can retry — not a 500 — since
 * the data is intact (no duplicate version was written).
 */
final class EventStreamConcurrencyConflict extends DomainException implements Conflict
{
    public static function forAggregate(string $aggregateId, string $aggregateType): self
    {
        return new self(
            type: 'event-stream-conflict',
            title: 'A concurrent change to the same aggregate was already recorded; retry the operation.',
            context: ['aggregateId' => $aggregateId, 'aggregateType' => $aggregateType],
        );
    }
}
