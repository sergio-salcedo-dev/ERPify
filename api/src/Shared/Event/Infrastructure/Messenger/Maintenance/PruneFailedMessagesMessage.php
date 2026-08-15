<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

use InvalidArgumentException;

/**
 * Scheduler tick that triggers the retention prune of the Messenger failure transport.
 *
 * Thirty days is the same window `handled_domain_event` and the revoked-session sweep already use, and it is
 * deliberately far shorter than the audit windows: `failed` holds operational evidence, not a compliance
 * trail. What sets the floor is not tidiness but the dead-letter alarm — it reports the age of the oldest
 * surviving row, so a window narrow enough to reach rows the alarm has not yet raised would cap that age and
 * quiet the queue by deleting its evidence. Against {@see ReportDeadLetterBacklogMessage}'s 24-hour threshold
 * this window is thirty times over, and the margin is asserted rather than assumed.
 */
final readonly class PruneFailedMessagesMessage
{
    /**
     * The floor is the dead-letter alarm's own age threshold, not a round number. Below it the prune reaches
     * rows the alarm has not had time to raise, which is the one failure this window exists to avoid — and a
     * zero would make the threshold *now* and empty the queue on the first tick, silently, with every gate
     * green. A negative would build `'--30 days'` and kill the tick with an unparseable date, daily, for ever.
     */
    private const int MINIMUM_RETENTION_DAYS = 2;

    public function __construct(
        public int $retentionDays = 30,
    ) {
        if ($retentionDays < self::MINIMUM_RETENTION_DAYS) {
            throw new InvalidArgumentException(\sprintf(
                'Failed-transport retention must be at least %d days, got %d.',
                self::MINIMUM_RETENTION_DAYS,
                $retentionDays,
            ));
        }
    }
}
