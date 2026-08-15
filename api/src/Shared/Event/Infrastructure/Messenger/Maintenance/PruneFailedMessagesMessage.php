<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

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
    public function __construct(
        public int $retentionDays = 30,
    ) {
    }
}
