<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Domain;

/**
 * Resolves the stable `(eventName, eventVersion)` key of a stored row back to the concrete
 * {@see DomainEvent} subclass (and its aggregate type). The FQCN is never persisted, so a class
 * rename never breaks reading the log; the implementation is built at compile time from a scan of
 * every concrete event class and fails the build on an `eventName` collision.
 */
interface DomainEventMapper
{
    /**
     * @return class-string<DomainEvent>
     */
    public function classFor(string $eventName, int $eventVersion): string;

    public function aggregateTypeFor(string $eventName, int $eventVersion): string;
}
