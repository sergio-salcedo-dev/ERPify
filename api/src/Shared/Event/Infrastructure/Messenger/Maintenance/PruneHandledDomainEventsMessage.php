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
     *
     * The floor need not reach the `failed` queue's 30-day window. A handler that succeeded leaves a `HandledStamp`
     * on the envelope, which travels with it into the retry and the `failed` queue, so Messenger itself skips it on
     * any later retry; a claim only covers a handler that ran but whose acknowledgement was lost, and the Doctrine
     * transport redelivers that within its one-hour `redeliver_timeout`.
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
