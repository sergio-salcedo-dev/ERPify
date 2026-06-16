<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

/**
 * Scheduler tick that triggers the periodic prune of the `handled_domain_event` claim store.
 * Retention only needs to outlast the Messenger redelivery window of any event (seconds for
 * auto-retries); 30 days is a generous operational margin that also leaves a short audit trail
 * of recently-handled events.
 */
final readonly class PruneHandledDomainEventsMessage
{
    public function __construct(
        public int $retentionDays = 30,
    ) {
    }
}
