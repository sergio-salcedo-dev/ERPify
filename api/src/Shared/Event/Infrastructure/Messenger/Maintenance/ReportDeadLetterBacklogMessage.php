<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

/**
 * Scheduler tick that triggers a dead-letter backlog check. Defaults make any resting message an
 * alarm ({@see $maxBacklog} 0) — appropriate while the only async consumer is a non-critical
 * notification email; raise {@see $maxBacklog} and lean on {@see $maxAgeHours} once a costlier
 * consumer joins the bus.
 */
final readonly class ReportDeadLetterBacklogMessage
{
    public function __construct(
        public int $maxBacklog = 0,
        public int $maxAgeHours = 24,
    ) {
    }
}
