<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

use InvalidArgumentException;

/**
 * Scheduler tick that triggers the periodic prune of the `handled_domain_event` claim store.
 * Retention only needs to outlast the Messenger redelivery window of any event (seconds for
 * auto-retries); 30 days is a generous operational margin that also leaves a short audit trail
 * of recently-handled events.
 */
final readonly class PruneHandledDomainEventsMessage
{
    /**
     * A claim is what makes a redelivered event a no-op, so the window must at least outlast a redelivery. A zero
     * would put the threshold at *now* and a negative in the future, and either deletes live claims on the first
     * tick — every event still being retried is then handled a second time, silently.
     */
    private const int MINIMUM_RETENTION_DAYS = 1;

    public function __construct(
        public int $retentionDays = 30,
    ) {
        if ($retentionDays < self::MINIMUM_RETENTION_DAYS) {
            throw new InvalidArgumentException(\sprintf(
                'Handled-event claim retention must be at least %d day, got %d.',
                self::MINIMUM_RETENTION_DAYS,
                $retentionDays,
            ));
        }
    }
}
