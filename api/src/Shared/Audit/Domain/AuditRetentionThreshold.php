<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

use DateTimeImmutable;

/**
 * One line of the retention deletion plan: rows of {@see $level} whose `occurred_on` is before
 * {@see $deleteBefore} are out of retention. The plan is data — {@see AuditRetentionPolicy} produces it
 * and the pruner executes it — so the per-level decision never leaks into the messaging/orchestration
 * layer as control flow.
 */
final readonly class AuditRetentionThreshold
{
    public function __construct(
        public AuditLevel $level,
        public DateTimeImmutable $deleteBefore,
    ) {
    }
}
